#!/usr/bin/env python3
"""kratos-send: sign and send security events to KRATOS EYE.

Standard library only (Python 3.10+). Credentials come from the environment,
never from command-line arguments (which leak into shell history and `ps`):

    export KRATOS_URL=http://localhost:8080
    export KRATOS_KEY_ID=krk_...
    export KRATOS_SECRET=krs_...

Examples:
    # One event from a file (or "-" for stdin)
    python kratos_send.py send event.json

    # Synthetic traffic: 200 events at ~20/s, with a brute-force burst
    python kratos_send.py simulate --scenario mixed --count 200 --rate 20

All simulated data is synthetic. Source addresses come only from the RFC 5737
documentation ranges (192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24), which are
never routed on the public internet.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import random
import sys
import time
import urllib.error
import urllib.request
import uuid
from datetime import datetime, timezone
from typing import Any, Iterator

SIGNATURE_VERSION = "v1"
SINGLE_PATH = "/api/v1/ingest/events"
BATCH_PATH = "/api/v1/ingest/events/batch"
DOC_NETWORKS = ("192.0.2.", "198.51.100.", "203.0.113.")


# --------------------------------------------------------------------------- signing


def canonical(method: str, path: str, timestamp: str, body: bytes) -> str:
    """Canonical request string (ADR-0006). Must match App\\Domain\\Eye\\RequestSigner."""
    return f"{method.upper()}\n{path}\n{timestamp}\n{hashlib.sha256(body).hexdigest()}"


def sign(secret: str, method: str, path: str, timestamp: str, body: bytes) -> str:
    mac = hmac.new(secret.encode(), canonical(method, path, timestamp, body).encode(), hashlib.sha256)
    return f"{SIGNATURE_VERSION}={mac.hexdigest()}"


# --------------------------------------------------------------------------- client


class KratosClient:
    def __init__(self, base_url: str, key_id: str, secret: str, timeout: float = 15.0) -> None:
        self.base_url = base_url.rstrip("/")
        self.key_id = key_id
        self._secret = secret
        self.timeout = timeout

    @classmethod
    def from_env(cls) -> "KratosClient":
        missing = [name for name in ("KRATOS_URL", "KRATOS_KEY_ID", "KRATOS_SECRET") if not os.environ.get(name)]
        if missing:
            raise SystemExit(f"Missing environment variables: {', '.join(missing)}")
        return cls(os.environ["KRATOS_URL"], os.environ["KRATOS_KEY_ID"], os.environ["KRATOS_SECRET"])

    def post(self, path: str, payload: Any, idempotency_key: str | None = None) -> tuple[int, dict[str, Any]]:
        body = json.dumps(payload, separators=(",", ":")).encode()
        timestamp = str(int(time.time()))
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": "kratos-send/1.0",
            "X-Kratos-Key": self.key_id,
            "X-Kratos-Timestamp": timestamp,
            "X-Kratos-Signature": sign(self._secret, "POST", path, timestamp, body),
        }
        if idempotency_key:
            headers["Idempotency-Key"] = idempotency_key

        request = urllib.request.Request(self.base_url + path, data=body, headers=headers, method="POST")
        try:
            with urllib.request.urlopen(request, timeout=self.timeout) as response:
                return response.status, json.loads(response.read() or b"{}")
        except urllib.error.HTTPError as error:
            try:
                return error.code, json.loads(error.read() or b"{}")
            except json.JSONDecodeError:
                return error.code, {}

    def send_event(self, event: dict[str, Any], idempotency_key: str | None = None) -> tuple[int, dict[str, Any]]:
        return self.post(SINGLE_PATH, event, idempotency_key)

    def send_batch(self, events: list[dict[str, Any]]) -> tuple[int, dict[str, Any]]:
        return self.post(BATCH_PATH, {"events": events})


# --------------------------------------------------------------------------- simulation


def now_rfc3339() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def doc_ip(rng: random.Random) -> str:
    return rng.choice(DOC_NETWORKS) + str(rng.randint(1, 254))


def baseline_event(rng: random.Random, asset: str | None) -> dict[str, Any]:
    """Ordinary background activity."""
    choice = rng.random()
    if choice < 0.55:
        event: dict[str, Any] = {
            "event_type": "authentication_success",
            "source": "identity_provider",
            "severity": "info",
            "metadata": {"source_ip": doc_ip(rng), "username": f"user{rng.randint(1, 40)}", "auth_method": "password+totp"},
        }
    elif choice < 0.80:
        event = {
            "event_type": "authentication_failure",
            "source": "identity_provider",
            "severity": "low",
            "metadata": {"source_ip": doc_ip(rng), "username": f"user{rng.randint(1, 40)}", "failed_attempts": 1},
        }
    elif choice < 0.92:
        event = {
            "event_type": "firewall_block",
            "source": "edge_firewall",
            "severity": "low",
            "metadata": {"source_ip": doc_ip(rng), "destination_port": rng.choice([22, 23, 3389, 445]), "protocol": "tcp"},
        }
    else:
        event = {
            "event_type": "permission_denied",
            "source": "application_server",
            "severity": "medium",
            "metadata": {"source_ip": doc_ip(rng), "username": f"user{rng.randint(1, 40)}", "resource": "/admin/reports", "action": "read"},
        }
    return finalise(event, asset)


def brute_force_burst(rng: random.Random, asset: str | None, size: int) -> Iterator[dict[str, Any]]:
    """One attacker address hammering logins, escalating in severity."""
    attacker = doc_ip(rng)
    target = f"user{rng.randint(1, 40)}"
    for attempt in range(1, size + 1):
        severity = "medium" if attempt < size * 0.5 else "high"
        yield finalise(
            {
                "event_type": "authentication_failure",
                "source": "identity_provider",
                "severity": severity,
                "metadata": {"source_ip": attacker, "username": target, "failed_attempts": attempt, "auth_method": "password"},
            },
            asset,
        )
    yield finalise({"event_type": "account_lockout", "source": "identity_provider", "severity": "high",
                    "metadata": {"source_ip": attacker, "username": target}}, asset)


def finalise(event: dict[str, Any], asset: str | None) -> dict[str, Any]:
    event["timestamp"] = now_rfc3339()
    event["idempotency_key"] = f"sim-{uuid.uuid4()}"
    event["metadata"]["synthetic"] = True  # always labelled
    if asset:
        event["asset"] = asset
    return event


def scenario(name: str, count: int, rng: random.Random, asset: str | None) -> Iterator[dict[str, Any]]:
    produced = 0
    burst_at = count // 2 if name == "mixed" else -1
    while produced < count:
        if name == "brute-force" or produced == burst_at:
            for event in brute_force_burst(rng, asset, size=min(25, count - produced)):
                if produced >= count:
                    return
                yield event
                produced += 1
            if name == "brute-force":
                return
            continue
        yield baseline_event(rng, asset)
        produced += 1


# --------------------------------------------------------------------------- CLI


def cmd_send(args: argparse.Namespace) -> int:
    raw = sys.stdin.read() if args.file == "-" else open(args.file, encoding="utf-8").read()
    payload = json.loads(raw)
    client = KratosClient.from_env()
    if isinstance(payload, list):
        status, body = client.send_batch(payload)
    else:
        status, body = client.send_event(payload, args.idempotency_key)
    print(json.dumps({"status": status, "response": body}, indent=2))
    return 0 if status < 400 else 1


def cmd_simulate(args: argparse.Namespace) -> int:
    client = KratosClient.from_env()
    rng = random.Random(args.seed)
    interval = 1.0 / args.rate if args.rate > 0 else 0.0
    counts: dict[int, int] = {}
    started = time.monotonic()

    for index, event in enumerate(scenario(args.scenario, args.count, rng, args.asset)):
        status, body = client.send_event(event)
        counts[status] = counts.get(status, 0) + 1
        if status >= 400 and args.verbose:
            print(f"#{index}: HTTP {status} {json.dumps(body)}", file=sys.stderr)
        if interval:
            # Pace against the schedule, not the previous request.
            delay = started + (index + 1) * interval - time.monotonic()
            if delay > 0:
                time.sleep(delay)

    elapsed = time.monotonic() - started
    print(json.dumps({"sent": sum(counts.values()), "by_status": counts, "elapsed_seconds": round(elapsed, 2)}, indent=2))
    return 0 if all(code < 400 for code in counts) else 1


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="kratos-send", description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest="command", required=True)

    send = sub.add_parser("send", help="send one event (JSON object) or a batch (JSON array)")
    send.add_argument("file", help="path to JSON file, or - for stdin")
    send.add_argument("--idempotency-key")
    send.set_defaults(func=cmd_send)

    sim = sub.add_parser("simulate", help="generate labelled synthetic traffic")
    sim.add_argument("--scenario", choices=["baseline", "brute-force", "mixed"], default="mixed")
    sim.add_argument("--count", type=int, default=100)
    sim.add_argument("--rate", type=float, default=10.0, help="events per second (0 = as fast as possible)")
    sim.add_argument("--asset", help="registered asset name to attach")
    sim.add_argument("--seed", type=int, help="random seed for reproducible runs")
    sim.add_argument("--verbose", action="store_true")
    sim.set_defaults(func=cmd_simulate)

    return parser


def main(argv: list[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())

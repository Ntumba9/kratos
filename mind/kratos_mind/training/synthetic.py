"""Seeded synthetic dataset generator.

Produces labelled (event, context) pairs — the same shape CORE sends at
serving time — so training and evaluation exercise the real feature code.

THIS DATA IS SYNTHETIC. Distributions are hand-specified to resemble common
patterns (diurnal activity, Poisson-like event counts, a few attack
scenarios). Metrics measured on it say how well the detector separates *these*
distributions; they are not estimates of real-world detection accuracy.

Scenarios
    normal                 everyday traffic (label 0)
    brute_force            many failures from one IP against one or two accounts
    credential_stuffing    one IP, many usernames
    permission_probing     one user repeatedly denied
    api_abuse              request volume far above normal
    off_hours_admin        privileged changes at night / weekends
    activity_surge         an asset suddenly far above its baseline
    exfiltration           large outbound transfer reported
    low_and_slow           deliberately subtle credential attack — below rule
                           thresholds; included to measure (and expose) blind spots
"""

from __future__ import annotations

from dataclasses import dataclass
from datetime import UTC, datetime, timedelta
from typing import Any

import numpy as np

ATTACK_SCENARIOS = (
    "brute_force",
    "credential_stuffing",
    "permission_probing",
    "api_abuse",
    "off_hours_admin",
    "activity_surge",
    "exfiltration",
    "low_and_slow",
)

# Normal traffic mix: (event_type, weight, severity)
NORMAL_MIX = (
    ("authentication_success", 0.46, "info"),
    ("authentication_failure", 0.14, "low"),
    ("firewall_block", 0.16, "low"),
    ("permission_denied", 0.06, "medium"),
    ("api_request_anomaly", 0.03, "medium"),
    ("configuration_change", 0.03, "medium"),
    ("health_check_failure", 0.05, "low"),
    ("rate_limit_exceeded", 0.04, "low"),
    ("mfa_failure", 0.03, "low"),
)

# Share of daily activity per UTC hour (business-hours peak).
_DIURNAL = np.array([1, 1, 1, 1, 1, 2, 3, 6, 9, 10, 10, 9, 8, 9, 10, 10, 9, 7, 5, 4, 3, 2, 2, 1], dtype=float)
DIURNAL = _DIURNAL / _DIURNAL.sum()

EPOCH = datetime(2026, 9, 7, tzinfo=UTC)  # a Monday; fixed for reproducibility


@dataclass(frozen=True)
class Sample:
    event: dict[str, Any]
    context: dict[str, Any]
    label: int
    scenario: str


class Generator:
    def __init__(self, seed: int):
        self.rng = np.random.default_rng(seed)
        self._n = 0

    # ---------------------------------------------------------------- helpers

    def _ip(self) -> str:
        return f"{self.rng.choice(['192.0.2', '198.51.100', '203.0.113'])}.{self.rng.integers(1, 255)}"

    def _time(self, hours: np.ndarray | None = None, weekend_prob: float = 0.08) -> datetime:
        hour = int(self.rng.choice(24, p=DIURNAL if hours is None else hours))
        day = int(self.rng.choice([5, 6])) if self.rng.random() < weekend_prob else int(self.rng.integers(0, 5))
        return EPOCH + timedelta(days=day + 7 * int(self.rng.integers(0, 4)), hours=hour, minutes=int(self.rng.integers(0, 60)))

    def _base_context(self, event_type: str, occurred: datetime) -> dict[str, Any]:
        mean = float(self.rng.uniform(5, 60))
        std = float(np.sqrt(mean) * self.rng.uniform(0.8, 1.5))
        type_weight = dict((t, w) for t, w, _ in NORMAL_MIX).get(event_type, 0.01)
        return {
            "src_ip_events_5m": int(self.rng.poisson(1.5)) + 1,
            "src_ip_auth_failures_15m": int(self.rng.poisson(0.2)),
            "src_ip_distinct_users_1h": int(self.rng.integers(0, 2)),
            "user_auth_failures_15m": int(self.rng.poisson(0.2)),
            "user_permission_denied_1h": int(self.rng.poisson(0.3)),
            "asset_events_1h": int(max(0.0, self.rng.normal(mean, std))),
            "asset_hourly_mean_7d": round(mean, 3),
            "asset_hourly_std_7d": round(std, 3),
            "type_share_7d": float(np.clip(type_weight * self.rng.uniform(0.7, 1.3), 0.0005, 1.0)),
            "type_hour_share_7d": float(np.clip(DIURNAL[occurred.hour] * self.rng.uniform(0.7, 1.3), 0.0, 1.0)),
            "org_events_7d": int(self.rng.integers(5_000, 60_000)),
        }

    def _event(self, event_type: str, severity: str, occurred: datetime, metadata: dict[str, Any], ip: str | None = None) -> dict[str, Any]:
        self._n += 1
        return {
            "id": f"syn-{self._n}",
            "event_type": event_type,
            "source": "synthetic",
            "severity": severity,
            "occurred_at": occurred,
            "source_ip": ip,
            "metadata": {**metadata, "synthetic": True},
        }

    # --------------------------------------------------------------- scenarios

    def normal(self) -> Sample:
        types, weights, severities = zip(*NORMAL_MIX, strict=True)
        i = int(self.rng.choice(len(types), p=np.array(weights) / sum(weights)))
        event_type, severity = types[i], severities[i]
        occurred = self._time()
        ctx = self._base_context(event_type, occurred)
        ip = self._ip()
        md: dict[str, Any] = {"source_ip": ip}

        if event_type == "authentication_failure":
            ctx["src_ip_auth_failures_15m"] += 1 + int(self.rng.poisson(0.6))
            ctx["user_auth_failures_15m"] += 1 + int(self.rng.poisson(0.4))
            ctx["src_ip_distinct_users_1h"] = max(1, ctx["src_ip_distinct_users_1h"])
            md |= {"username": f"user{self.rng.integers(1, 400)}", "failed_attempts": int(ctx["user_auth_failures_15m"])}
        elif event_type == "permission_denied":
            ctx["user_permission_denied_1h"] += 1 + int(self.rng.poisson(1.0))
            md |= {"username": f"user{self.rng.integers(1, 400)}"}
        elif event_type == "api_request_anomaly":
            md |= {"request_count": int(self.rng.integers(60, 400)), "window_seconds": 60}
        elif event_type == "rate_limit_exceeded":
            md |= {"request_count": int(self.rng.integers(100, 500))}
        elif event_type == "configuration_change":
            occurred = self._time(hours=np.r_[np.zeros(8), np.ones(10), np.zeros(6)] / 10, weekend_prob=0.0)
            ctx["type_hour_share_7d"] = float(self.rng.uniform(0.06, 0.14))
            md |= {"username": f"admin{self.rng.integers(1, 6)}", "setting": "feature_flag"}

        return Sample(self._event(event_type, severity, occurred, md, ip), ctx, 0, "normal")

    def attack(self, scenario: str) -> Sample:
        ip = self._ip()
        occurred = self._time()

        if scenario == "brute_force":
            ctx = self._base_context("authentication_failure", occurred)
            n = int(self.rng.integers(12, 90))
            ctx |= {
                "src_ip_auth_failures_15m": n,
                "src_ip_events_5m": int(n * self.rng.uniform(0.3, 0.6)) + 1,
                "src_ip_distinct_users_1h": int(self.rng.integers(1, 3)),
                "user_auth_failures_15m": int(n * self.rng.uniform(0.6, 1.0)),
            }
            return Sample(
                self._event(
                    "authentication_failure",
                    str(self.rng.choice(["medium", "high"])),
                    occurred,
                    {"source_ip": ip, "username": "finance.lead", "failed_attempts": ctx["user_auth_failures_15m"]},
                    ip,
                ),
                ctx,
                1,
                scenario,
            )

        if scenario == "credential_stuffing":
            ctx = self._base_context("authentication_failure", occurred)
            users = int(self.rng.integers(8, 70))
            ctx |= {
                "src_ip_auth_failures_15m": int(users * self.rng.uniform(0.5, 1.2)) + 1,
                "src_ip_distinct_users_1h": users,
                "src_ip_events_5m": int(users * 0.3) + 1,
                "user_auth_failures_15m": int(self.rng.integers(1, 3)),
            }
            return Sample(
                self._event("authentication_failure", "medium", occurred, {"source_ip": ip, "username": f"user{self.rng.integers(1, 400)}", "failed_attempts": 1}, ip),
                ctx,
                1,
                scenario,
            )

        if scenario == "permission_probing":
            ctx = self._base_context("permission_denied", occurred)
            ctx["user_permission_denied_1h"] = int(self.rng.integers(12, 80))
            return Sample(self._event("permission_denied", "medium", occurred, {"source_ip": ip, "username": "contractor7", "resource": "/admin"}, ip), ctx, 1, scenario)

        if scenario == "api_abuse":
            ctx = self._base_context("api_request_anomaly", occurred)
            ctx["src_ip_events_5m"] = int(self.rng.integers(5, 40))
            return Sample(
                self._event(
                    "api_request_anomaly",
                    "medium",
                    occurred,
                    {"source_ip": ip, "endpoint": "/v1/export", "request_count": int(self.rng.integers(3_000, 40_000)), "window_seconds": 60},
                    ip,
                ),
                ctx,
                1,
                scenario,
            )

        if scenario == "off_hours_admin":
            night = np.r_[np.ones(5), np.zeros(19)] / 5
            occurred = self._time(hours=night, weekend_prob=0.3)
            event_type = str(self.rng.choice(["privilege_escalation", "configuration_change"]))
            ctx = self._base_context(event_type, occurred)
            ctx["type_hour_share_7d"] = float(self.rng.uniform(0.0, 0.006))
            return Sample(self._event(event_type, "medium", occurred, {"username": "svc-backup", "from_role": "viewer", "to_role": "admin"}), ctx, 1, scenario)

        if scenario == "activity_surge":
            event_type = str(self.rng.choice(["firewall_block", "authentication_success", "api_request_anomaly"]))
            ctx = self._base_context(event_type, occurred)
            ctx["asset_events_1h"] = int(ctx["asset_hourly_mean_7d"] + self.rng.uniform(6, 20) * max(ctx["asset_hourly_std_7d"], 1.0)) + 20
            return Sample(self._event(event_type, "low", occurred, {"source_ip": ip}, ip), ctx, 1, scenario)

        if scenario == "exfiltration":
            ctx = self._base_context("data_exfiltration_suspected", occurred)
            return Sample(
                self._event(
                    "data_exfiltration_suspected",
                    "critical",
                    occurred,
                    {"source_ip": "192.0.2.30", "destination_ip": ip, "bytes_transferred": int(self.rng.integers(5e8, 5e9))},
                    ip,
                ),
                ctx,
                1,
                scenario,
            )

        if scenario == "low_and_slow":
            ctx = self._base_context("authentication_failure", occurred)
            ctx |= {
                "src_ip_auth_failures_15m": int(self.rng.integers(3, 7)),
                "src_ip_distinct_users_1h": int(self.rng.integers(2, 5)),
                "user_auth_failures_15m": int(self.rng.integers(2, 5)),
            }
            return Sample(
                self._event(
                    "authentication_failure",
                    "low",
                    occurred,
                    {"source_ip": ip, "username": f"user{self.rng.integers(1, 400)}", "failed_attempts": ctx["user_auth_failures_15m"]},
                    ip,
                ),
                ctx,
                1,
                scenario,
            )

        raise ValueError(f"Unknown scenario {scenario}")

    def dataset(self, normal: int, per_attack: int = 0) -> list[Sample]:
        samples = [self.normal() for _ in range(normal)]
        for scenario in ATTACK_SCENARIOS:
            samples.extend(self.attack(scenario) for _ in range(per_attack))
        return samples

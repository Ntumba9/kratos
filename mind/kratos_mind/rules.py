"""Deterministic detection rules.

Each rule is a pure function of (event, context, parameters). Parameters and
enablement come from CORE's ``detection_rules`` table, so organisations can
tune them; ``DEFAULT_PARAMETERS`` documents the defaults (kept identical to
core/config/kratos.php — a test checks the two match).

A rule returns a ``RuleHit`` with a 0–100 score and a human-readable
explanation that cites the observed values and thresholds — never a vague
"suspicious activity".
"""

from __future__ import annotations

from collections.abc import Callable, Mapping
from datetime import datetime
from typing import Any

from .schemas import SEVERITY_RANK, RuleConfig, RuleHit

SEVERITY_SCORE = {"info": 10.0, "low": 30.0, "medium": 55.0, "high": 75.0, "critical": 90.0}

AUTH_FAILURE = "authentication_failure"
PRIVILEGED_CHANGE_TYPES = {"privilege_escalation", "configuration_change"}
CRITICAL_SIGNATURE_TYPES = {"malware_detected", "data_exfiltration_suspected", "suspicious_process"}

DEFAULT_PARAMETERS: dict[str, dict[str, float]] = {
    "brute_force_source": {"failures_15m": 10},
    "credential_stuffing": {"distinct_users_1h": 5},
    "targeted_account_attack": {"failures_15m": 8},
    "lockout_after_failures": {"failures_15m": 3},
    "permission_probing": {"denials_1h": 10},
    "api_volume_spike": {"requests_per_second": 10},
    "off_hours_privileged_change": {"business_start_hour": 7, "business_end_hour": 19},
    "activity_surge": {"z_threshold": 4, "min_events_1h": 20, "min_baseline_mean": 0.5},
    "abnormal_timing": {"max_hour_share": 0.004, "min_type_events_7d": 200},
    "critical_signature": {},
}

CATEGORY = {
    "brute_force_source": "credential_attack",
    "credential_stuffing": "credential_attack",
    "targeted_account_attack": "credential_attack",
    "lockout_after_failures": "credential_attack",
    "permission_probing": "reconnaissance",
    "api_volume_spike": "abuse",
    "off_hours_privileged_change": "privilege_misuse",
    "activity_surge": "anomalous_activity",
    "abnormal_timing": "anomalous_activity",
    "critical_signature": "compromise",
}

Rule = Callable[[Mapping[str, Any], Mapping[str, Any], Mapping[str, float]], tuple[bool, float, str, dict[str, Any]] | None]


# How far intensity (observed / threshold) can lift a hit above its severity's
# base score. Severity bounds the score: a medium rule can never look critical.
INTENSITY_HEADROOM = 15.0


def _scaled(base: float, intensity: float) -> float:
    """base at the threshold, rising with sqrt(intensity - 1), at most INTENSITY_HEADROOM above base."""
    lift = min(INTENSITY_HEADROOM, 5.0 * max(0.0, intensity - 1.0) ** 0.5)
    return min(100.0, base + lift)


def brute_force_source(e, c, p):
    n, t = c["src_ip_auth_failures_15m"], p["failures_15m"]
    if e["event_type"] == AUTH_FAILURE and e.get("source_ip") and n >= t:
        return True, n / t, f"{n} failed logins from {e['source_ip']} in 15 minutes (threshold {t:g})", {"failures_15m": n, "threshold": t}
    return None


def credential_stuffing(e, c, p):
    n, t = c["src_ip_distinct_users_1h"], p["distinct_users_1h"]
    if e["event_type"] == AUTH_FAILURE and e.get("source_ip") and n >= t:
        return True, n / t, f"{e['source_ip']} tried {n} different usernames in 1 hour (threshold {t:g})", {"distinct_users_1h": n, "threshold": t}
    return None


def targeted_account_attack(e, c, p):
    n, t = c["user_auth_failures_15m"], p["failures_15m"]
    user = (e.get("metadata") or {}).get("username")
    if e["event_type"] == AUTH_FAILURE and user and n >= t:
        return True, n / t, f"{n} failed logins for account '{user}' in 15 minutes (threshold {t:g})", {"failures_15m": n, "threshold": t}
    return None


def lockout_after_failures(e, c, p):
    n, t = c["user_auth_failures_15m"], p["failures_15m"]
    if e["event_type"] == "account_lockout" and n >= t:
        user = (e.get("metadata") or {}).get("username", "unknown")
        return True, n / t, f"Account '{user}' locked out after {n} failures in 15 minutes", {"failures_15m": n, "threshold": t}
    return None


def permission_probing(e, c, p):
    n, t = c["user_permission_denied_1h"], p["denials_1h"]
    user = (e.get("metadata") or {}).get("username")
    if e["event_type"] == "permission_denied" and user and n >= t:
        return True, n / t, f"'{user}' was denied access {n} times in 1 hour (threshold {t:g})", {"denials_1h": n, "threshold": t}
    return None


def api_volume_spike(e, c, p):
    md = e.get("metadata") or {}
    count, window = md.get("request_count"), md.get("window_seconds") or 60
    if e["event_type"] in {"api_request_anomaly", "rate_limit_exceeded"} and isinstance(count, (int, float)) and window:
        rate, t = float(count) / float(window), p["requests_per_second"]
        if rate >= t:
            return (
                True,
                rate / t,
                f"{count} requests in {window:g}s ({rate:.1f}/s, threshold {t:g}/s) to {md.get('endpoint', 'an endpoint')}",
                {"requests_per_second": round(rate, 2), "threshold": t},
            )
    return None


def off_hours_privileged_change(e, c, p):
    if e["event_type"] not in PRIVILEGED_CHANGE_TYPES:
        return None
    occurred: datetime = e["occurred_at"]
    start, end = p["business_start_hour"], p["business_end_hour"]
    weekend = occurred.weekday() >= 5
    if weekend or not (start <= occurred.hour < end):
        when = "on a weekend" if weekend else f"at {occurred:%H:%M} UTC (business hours {start:02.0f}:00–{end:02.0f}:00)"
        return True, 1.0, f"Privileged change ({e['event_type']}) {when}", {"hour_utc": occurred.hour, "weekend": int(weekend)}
    return None


def activity_surge(e, c, p):
    # Cold start: without an established baseline there is nothing to surge above.
    if c["asset_hourly_mean_7d"] < p["min_baseline_mean"]:
        return None
    std = max(c["asset_hourly_std_7d"], 1.0)
    z = (c["asset_events_1h"] - c["asset_hourly_mean_7d"]) / std
    if c["asset_events_1h"] >= p["min_events_1h"] and z >= p["z_threshold"]:
        return (
            True,
            z / p["z_threshold"],
            (
                f"{c['asset_events_1h']} events on this asset in the last hour vs a 7-day hourly mean of "
                f"{c['asset_hourly_mean_7d']:.1f} (z = {z:.1f}, threshold {p['z_threshold']:g})"
            ),
            {"events_1h": c["asset_events_1h"], "baseline_mean": round(c["asset_hourly_mean_7d"], 2), "z": round(z, 2)},
        )
    return None


def abnormal_timing(e, c, p):
    share, t = c["type_hour_share_7d"], p["max_hour_share"]
    type_events = c["type_share_7d"] * c["org_events_7d"]
    if type_events >= p["min_type_events_7d"] and share <= t:
        occurred: datetime = e["occurred_at"]
        return (
            True,
            1.0,
            (f"{e['event_type']} at {occurred:%H}:00 UTC is unusual: only {share:.2%} of this type occurred in that hour over 7 days (threshold {t:.0%})"),
            {"hour_share_7d": round(share, 4), "threshold": t},
        )
    return None


def critical_signature(e, c, p):
    if e["event_type"] in CRITICAL_SIGNATURE_TYPES:
        return True, 1.0, f"Reported {e['event_type'].replace('_', ' ')} (source: {e['source']})", {"event_type": e["event_type"]}
    return None


RULES: dict[str, Rule] = {
    "brute_force_source": brute_force_source,
    "credential_stuffing": credential_stuffing,
    "targeted_account_attack": targeted_account_attack,
    "lockout_after_failures": lockout_after_failures,
    "permission_probing": permission_probing,
    "api_volume_spike": api_volume_spike,
    "off_hours_privileged_change": off_hours_privileged_change,
    "activity_surge": activity_surge,
    "abnormal_timing": abnormal_timing,
    "critical_signature": critical_signature,
}


def evaluate(event: Mapping[str, Any], context: Mapping[str, Any], configs: list[RuleConfig]) -> list[RuleHit]:
    """Run every enabled, known rule. Unknown keys are ignored (forward compatibility)."""
    hits: list[RuleHit] = []
    for config in configs:
        rule = RULES.get(config.key)
        if rule is None or not config.enabled:
            continue
        params = {**DEFAULT_PARAMETERS[config.key], **config.parameters}
        outcome = rule(event, context, params)
        if outcome is None:
            continue
        _, intensity, explanation, observed = outcome
        base = SEVERITY_SCORE[config.severity]
        # Never score a rule hit below the event's own reported severity.
        floor = SEVERITY_SCORE[event["severity"]] if SEVERITY_RANK[event["severity"]] >= SEVERITY_RANK["high"] else 0.0
        score = max(floor, _scaled(base, intensity))
        hits.append(
            RuleHit(
                key=config.key,
                severity=config.severity,
                score=round(score, 1),
                category=CATEGORY[config.key],
                explanation=explanation,
                observed=observed,
            )
        )
    return sorted(hits, key=lambda h: h.score, reverse=True)


def default_configs() -> list[RuleConfig]:
    """Built-in rule set with default severities (used by training/evaluation)."""
    severities = {
        "brute_force_source": "high",
        "credential_stuffing": "high",
        "targeted_account_attack": "medium",
        "lockout_after_failures": "high",
        "permission_probing": "medium",
        "api_volume_spike": "medium",
        "off_hours_privileged_change": "medium",
        "activity_surge": "medium",
        "abnormal_timing": "low",
        "critical_signature": "critical",
    }
    return [RuleConfig(key=k, severity=severities[k], parameters={}) for k in RULES]

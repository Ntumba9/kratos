"""Risk scoring: combine rule hits, anomaly evidence and reported severity.

Each source of evidence becomes a component in [0, 100]:

    severity    reported severity  (info 5, low 15, medium 30, high 50, critical 70)
    rules       highest rule-hit score
    anomaly     0 below the model's threshold percentile (p99); 30 at the
                threshold, rising linearly to 60 at the 100th
    statistical 15 per robust-z unit above the threshold, capped at 45

Components are combined with a noisy-OR — independent pieces of evidence add
up, but no single weak signal dominates and the result never exceeds 100:

    combined = 1 − Π (1 − component / 100)

then scaled by asset criticality (low 0.85, medium 1.0, high 1.1, critical 1.2)
and clamped to [0, 100]. Verdict: < 40 benign, 40–69 suspicious, ≥ 70 malicious.

These weights are engineering judgement, documented so they can be reviewed
and tuned; they are not learned from labelled incidents.
"""

from __future__ import annotations

from .schemas import AnomalyResult, RuleHit, StatisticalResult

SEVERITY_COMPONENT = {"info": 5.0, "low": 15.0, "medium": 30.0, "high": 50.0, "critical": 70.0}
CRITICALITY_FACTOR = {"low": 0.85, "medium": 1.0, "high": 1.1, "critical": 1.2}

SUSPICIOUS_AT = 40.0
MALICIOUS_AT = 70.0

EVENT_TYPE_CATEGORY = {
    "malware_detected": "compromise",
    "suspicious_process": "compromise",
    "data_exfiltration_suspected": "exfiltration",
    "port_scan_detected": "reconnaissance",
    "firewall_block": "reconnaissance",
    "privilege_escalation": "privilege_misuse",
    "service_unavailable": "availability",
    "health_check_failure": "availability",
}


def components(severity: str, hits: list[RuleHit], anomaly: AnomalyResult, statistical: StatisticalResult, statistical_threshold: float) -> dict[str, float]:
    # Only evidence beyond the model's own threshold counts: by construction ~1 % of
    # normal traffic exceeds p99, whereas ~10 % exceeds p90.
    if anomaly.percentile < anomaly.threshold_percentile:
        anomaly_component = 0.0
    else:
        span = max(100.0 - anomaly.threshold_percentile, 1e-9)
        anomaly_component = min(60.0, 30.0 + 30.0 * (anomaly.percentile - anomaly.threshold_percentile) / span)
    excess_z = max(0.0, statistical.max_robust_z - statistical_threshold)
    statistical_component = min(45.0, 15.0 * excess_z) if statistical.flagged else 0.0
    return {
        "severity": SEVERITY_COMPONENT[severity],
        "rules": max((h.score for h in hits), default=0.0),
        "anomaly": round(anomaly_component, 2),
        "statistical": round(statistical_component, 2),
    }


def combine(parts: dict[str, float], criticality: str | None) -> float:
    remaining = 1.0
    for value in parts.values():
        remaining *= 1.0 - min(100.0, max(0.0, value)) / 100.0
    combined = 100.0 * (1.0 - remaining)
    factor = CRITICALITY_FACTOR.get(criticality or "medium", 1.0)
    return round(min(100.0, max(0.0, combined * factor)), 1)


def verdict(risk: float) -> str:
    if risk >= MALICIOUS_AT:
        return "malicious"
    if risk >= SUSPICIOUS_AT:
        return "suspicious"
    return "benign"


def category(event_type: str, hits: list[RuleHit], anomaly: AnomalyResult, risk: float) -> str:
    if hits:
        return hits[0].category
    if event_type in EVENT_TYPE_CATEGORY:
        return EVENT_TYPE_CATEGORY[event_type]
    if anomaly.is_anomalous and risk >= SUSPICIOUS_AT:
        return "anomalous_activity"
    return "none"


def summarise(
    risk: float,
    verdict_: str,
    hits: list[RuleHit],
    anomaly: AnomalyResult,
    statistical: StatisticalResult,
    parts: dict[str, float] | None = None,
    severity: str | None = None,
    criticality: str | None = None,
) -> str:
    """Deterministic, evidence-only summary — no generated prose, no speculation."""
    out = [f"Risk {risk:.0f} ({verdict_})."]
    if parts:
        driver = max(parts, key=lambda k: parts[k])
        label = {"severity": "reported severity", "rules": "detection rules", "anomaly": "anomaly model", "statistical": "statistical outlier"}[driver]
        out.append(f"Main driver: {label} ({parts[driver]:.0f}).")
    if severity:
        factor = CRITICALITY_FACTOR.get(criticality or "medium", 1.0)
        out.append(f"Reported severity {severity}; asset criticality {criticality or 'unknown'} (x{factor:g}).")
    if hits:
        rule_text = "; ".join(f"{h.key}: {h.explanation}" for h in hits[:3])
        out.append(f"Rules: {rule_text}.")
    else:
        out.append("No detection rule matched.")
    if anomaly.is_anomalous and anomaly.top_features:
        drivers = ", ".join(f"{f.description} ({f.value:g} vs typical {f.typical:g})" for f in anomaly.top_features[:2])
        out.append(f"Anomaly model: percentile {anomaly.percentile:.1f}, driven by {drivers}.")
    else:
        out.append(f"Anomaly model: percentile {anomaly.percentile:.1f} (not anomalous).")
    if statistical.flagged:
        out.append(f"Statistical outlier: {statistical.flagged[0].description} (robust z {statistical.flagged[0].contribution:g}).")
    return " ".join(out)

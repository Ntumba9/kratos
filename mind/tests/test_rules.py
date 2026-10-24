"""Each rule fires on its scenario, stays quiet on normal traffic, and explains itself."""

from __future__ import annotations

import json
from datetime import UTC, datetime

import pytest

from kratos_mind import rules
from kratos_mind.schemas import RuleConfig
from kratos_mind.training.synthetic import ATTACK_SCENARIOS, Generator

EXPECTED_RULE = {
    "brute_force": "brute_force_source",
    "credential_stuffing": "credential_stuffing",
    "permission_probing": "permission_probing",
    "api_abuse": "api_volume_spike",
    "off_hours_admin": "off_hours_privileged_change",
    "activity_surge": "activity_surge",
    "exfiltration": "critical_signature",
}


def hit_keys(sample) -> set[str]:
    return {h.key for h in rules.evaluate(sample.event, sample.context, rules.default_configs())}


@pytest.mark.parametrize("scenario,rule", sorted(EXPECTED_RULE.items()))
def test_rule_fires_on_its_scenario(scenario: str, rule: str) -> None:
    gen = Generator(seed=11)
    fired = sum(rule in hit_keys(gen.attack(scenario)) for _ in range(50))
    assert fired == 50, f"{rule} fired on {fired}/50 {scenario} samples"


def test_rules_are_quiet_on_normal_traffic() -> None:
    gen = Generator(seed=12)
    samples = [gen.normal() for _ in range(2000)]
    noisy = [s for s in samples if hit_keys(s) - {"abnormal_timing"}]
    # Normal data occasionally contains a legitimately unusual event; demand < 1 %.
    assert len(noisy) / len(samples) < 0.01, [hit_keys(s) for s in noisy[:5]]


def test_low_and_slow_evades_credential_rules_by_design() -> None:
    """Documents a known blind spot rather than hiding it."""
    credential_rules = {k for k, v in rules.CATEGORY.items() if v == "credential_attack"}
    gen = Generator(seed=13)
    assert not any(hit_keys(gen.attack("low_and_slow")) & credential_rules for _ in range(50))


def test_explanations_cite_observed_values_and_thresholds() -> None:
    event = {
        "event_type": "authentication_failure",
        "severity": "medium",
        "source": "idp",
        "source_ip": "203.0.113.66",
        "occurred_at": datetime(2026, 10, 23, 12, tzinfo=UTC),
        "metadata": {"username": "finance.lead"},
    }
    context = {
        "src_ip_auth_failures_15m": 14,
        "src_ip_distinct_users_1h": 1,
        "user_auth_failures_15m": 3,
        "user_permission_denied_1h": 0,
        "asset_events_1h": 0,
        "asset_hourly_mean_7d": 0,
        "asset_hourly_std_7d": 0,
        "type_share_7d": 0.1,
        "type_hour_share_7d": 0.05,
        "org_events_7d": 100,
    }

    [hit] = rules.evaluate(event, context, [RuleConfig(key="brute_force_source", severity="high")])

    assert "14 failed logins from 203.0.113.66" in hit.explanation
    assert "threshold 10" in hit.explanation
    assert hit.observed == {"failures_15m": 14, "threshold": 10}
    assert 75 <= hit.score <= 100


def test_thresholds_disable_and_unknown_rules_are_respected() -> None:
    gen = Generator(seed=14)
    sample = gen.attack("brute_force")
    n = sample.context["src_ip_auth_failures_15m"]

    raised = [RuleConfig(key="brute_force_source", severity="high", parameters={"failures_15m": n + 1})]
    disabled = [RuleConfig(key="brute_force_source", severity="high", enabled=False)]
    unknown = [RuleConfig(key="rule_from_the_future", severity="high")]

    for configs in (raised, disabled, unknown):
        assert not rules.evaluate(sample.event, sample.context, configs)


def test_every_rule_has_defaults_and_a_category() -> None:
    assert set(rules.RULES) == set(rules.DEFAULT_PARAMETERS) == set(rules.CATEGORY)
    assert {c.key for c in rules.default_configs()} == set(rules.RULES)
    assert set(EXPECTED_RULE) | {"low_and_slow"} == set(ATTACK_SCENARIOS)


def test_defaults_match_the_shared_contract_used_by_core() -> None:
    """core/config/kratos.php is checked against the same file (DetectionRuleContractTest)."""
    from .conftest import CONTRACT

    shared = json.loads((CONTRACT.parent / "rule-defaults.json").read_text())
    assert shared == rules.DEFAULT_PARAMETERS


def test_severity_bounds_the_rule_score() -> None:
    """Intensity lifts a hit within its severity band; a medium rule never scores like a critical one."""
    gen = Generator(seed=15)
    sample = gen.attack("activity_surge")
    extreme = {**sample.context, "asset_events_1h": 100_000}
    [hit] = [h for h in rules.evaluate(sample.event, extreme, rules.default_configs()) if h.key == "activity_surge"]
    assert hit.score <= rules.SEVERITY_SCORE["medium"] + rules.INTENSITY_HEADROOM


def test_activity_surge_needs_an_established_baseline() -> None:
    gen = Generator(seed=16)
    sample = gen.attack("activity_surge")
    cold = {**sample.context, "asset_hourly_mean_7d": 0.0, "asset_hourly_std_7d": 0.0, "asset_events_1h": 500}
    assert "activity_surge" not in {h.key for h in rules.evaluate(sample.event, cold, rules.default_configs())}

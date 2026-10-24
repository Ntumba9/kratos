"""Anomaly model, explanations, scoring, registry integrity and reproducibility."""

from __future__ import annotations

import json
from pathlib import Path

import numpy as np
import pytest

from kratos_mind import scoring
from kratos_mind.analyser import analyse
from kratos_mind.features import FEATURE_NAMES, extract
from kratos_mind.registry import ModelIntegrityError, load
from kratos_mind.schemas import AnalyseRequest
from kratos_mind.training import train as training
from kratos_mind.training.synthetic import Generator


def test_feature_vector_shape_and_determinism() -> None:
    sample = Generator(1).normal()
    a, b = extract(sample.event, sample.context), extract(sample.event, sample.context)
    assert a.shape == (len(FEATURE_NAMES),)
    assert np.array_equal(a, b)
    assert np.isfinite(a).all()


def test_attacks_score_as_more_anomalous_than_normal(loaded) -> None:
    gen = Generator(99)
    normal = np.array([loaded.model.raw_scores(extract(s.event, s.context))[0] for s in (gen.normal() for _ in range(300))])
    attack = np.array([loaded.model.raw_scores(extract(s.event, s.context))[0] for s in (gen.attack("brute_force") for _ in range(100))])
    assert np.median(attack) > np.percentile(normal, 95)


def test_explanations_are_positive_and_described(loaded) -> None:
    sample = Generator(5).attack("brute_force")
    result = loaded.model.analyse(extract(sample.event, sample.context))

    assert result.top_features, "an attack sample must have at least one contributing feature"
    for f in result.top_features:
        assert f.contribution > 0 and f.description


def test_statistical_detector_flags_extreme_values_relative_to_the_event_type(loaded) -> None:
    """Isolation Forest splits within the training range, so it cannot rank *how far*
    beyond it a value lies; the per-event-type robust statistical detector covers
    extreme values (moderate attack volumes are the rules' job — see model card)."""
    gen = Generator(6)
    stuffing = gen.attack("credential_stuffing")
    extreme = {**stuffing.context, "src_ip_distinct_users_1h": 900, "src_ip_auth_failures_15m": 1500}
    flags = loaded.model.statistical(extract(stuffing.event, extreme), "authentication_failure").flagged
    assert {"log_src_ip_distinct_users_1h", "log_src_ip_auth_failures_15m"} <= {f.feature for f in flags}

    # 300 requests is ordinary for an API event: no flag, because the baseline is per type.
    api = next(s for s in (gen.normal() for _ in range(500)) if s.event["event_type"] == "api_request_anomaly")
    assert not loaded.model.statistical(extract(api.event, api.context), "api_request_anomaly").flagged


def test_normal_traffic_is_rarely_suspicious(loaded) -> None:
    from kratos_mind import rules, scoring

    gen = Generator(321)
    flagged = 0
    for sample in (gen.normal() for _ in range(1500)):
        x = extract(sample.event, sample.context)
        hits = rules.evaluate(sample.event, sample.context, rules.default_configs())
        parts = scoring.components(sample.event["severity"], hits, loaded.model.analyse(x, top=0), loaded.model.statistical(x, sample.event["event_type"]), 6.0)
        flagged += scoring.combine(parts, "medium") >= scoring.SUSPICIOUS_AT
    assert flagged / 1500 < 0.03


def test_percentiles_are_calibrated_on_training_normal(loaded) -> None:
    gen = Generator(123)
    pct = loaded.model.percentiles(loaded.model.raw_scores(np.vstack([extract(s.event, s.context) for s in (gen.normal() for _ in range(2000))])))
    # About 1 % of fresh normal traffic should exceed the p99 threshold.
    assert 0.002 <= (pct >= 99).mean() <= 0.03


def test_risk_combination_is_bounded_monotonic_and_documented() -> None:
    low = scoring.combine({"severity": 5, "rules": 0, "anomaly": 0, "statistical": 0}, "medium")
    more = scoring.combine({"severity": 5, "rules": 75, "anomaly": 0, "statistical": 0}, "medium")
    most = scoring.combine({"severity": 70, "rules": 100, "anomaly": 60, "statistical": 45}, "critical")
    assert low < more < most <= 100
    assert scoring.combine({"severity": 30, "rules": 55}, "critical") > scoring.combine({"severity": 30, "rules": 55}, "low")
    assert [scoring.verdict(v) for v in (39.9, 40, 69.9, 70)] == ["benign", "suspicious", "suspicious", "malicious"]


def test_full_analysis_of_the_contract_example(loaded, contract_example) -> None:
    result = analyse(AnalyseRequest.model_validate(contract_example), loaded)

    assert result.verdict == "malicious"
    assert result.category == "credential_attack"
    assert {h.key for h in result.rule_hits} >= {"brute_force_source", "targeted_account_attack"}
    assert result.summary.startswith(f"Risk {result.risk_score:.0f} (malicious).")
    assert "14 failed logins from 203.0.113.66" in result.summary
    assert "Main driver: detection rules" in result.summary
    assert "asset criticality critical (x1.2)" in result.summary
    assert result.model.artifact_sha256 == loaded.metadata["artifact_sha256"]


def test_benign_event_is_benign(loaded) -> None:
    gen = Generator(77)
    sample = next(s for s in (gen.normal() for _ in range(100)) if s.event["event_type"] == "authentication_success")
    request = AnalyseRequest.model_validate(
        {
            "event": {**sample.event, "occurred_at": sample.event["occurred_at"].isoformat(), "source_ip": sample.event["source_ip"]},
            "context": sample.context,
            "rules": [c.model_dump() for c in __import__("kratos_mind.rules", fromlist=["x"]).default_configs()],
        }
    )
    assert analyse(request, loaded).verdict == "benign"


def test_tampered_artifact_is_refused(models_dir: Path, tmp_path: Path) -> None:
    import shutil

    copy = tmp_path / "models"
    shutil.copytree(models_dir, copy)
    version = json.loads((copy / "registry.json").read_text())["active"]["isolation_forest"]
    artifact = copy / "isolation_forest" / version / "model.joblib"
    artifact.write_bytes(artifact.read_bytes() + b"tampered")

    with pytest.raises(ModelIntegrityError, match="hash mismatch"):
        load(copy)


def test_training_is_reproducible(tmp_path: Path) -> None:
    original = dict(training.DATASET)
    training.DATASET.update(train_normal=1500, test_normal=800, test_per_attack=25)
    try:
        a = training.train(seed=3, models_dir=tmp_path / "a")
        b = training.train(seed=3, models_dir=tmp_path / "b")
        c = training.train(seed=4, models_dir=tmp_path / "c")
    finally:
        training.DATASET.clear()
        training.DATASET.update(original)

    assert a["metrics"] == b["metrics"]
    assert a["metrics"] != c["metrics"]


def test_metadata_records_synthetic_provenance_and_limitations(loaded) -> None:
    md = loaded.metadata
    assert md["training_dataset"]["synthetic"] is True
    assert any("synthetic" in item.lower() for item in md["limitations"])
    assert set(md["metrics"]["anomaly_model"]) >= {"roc_auc", "pr_auc", "at_threshold", "detection_rate_by_scenario"}
    card = (loaded.path / "model_card.md").read_text()
    assert "not** an estimate of real-world detection accuracy" in card


def test_asset_activity_is_neutral_without_a_baseline() -> None:
    from kratos_mind.features import FEATURE_NAMES

    sample = Generator(8).normal()
    cold = {**sample.context, "asset_hourly_mean_7d": 0.0, "asset_hourly_std_7d": 0.0, "asset_events_1h": 400}
    assert extract(sample.event, cold)[FEATURE_NAMES.index("asset_activity_z")] == 0.0

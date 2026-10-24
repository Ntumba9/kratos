"""Reproducible training and evaluation of the anomaly model.

    python -m kratos_mind.training.train [--seed 42] [--models-dir models] [--if-missing]

1. Generate a seeded synthetic dataset (training: normal only; test: normal +
   labelled attack scenarios — never seen in training).
2. Fit the Isolation Forest + statistical baselines on training-normal data.
3. Evaluate the anomaly model alone, the rules alone, and the complete risk
   score (exactly the serving code path) on the held-out test set.
4. Write model.joblib, metadata.json (incl. SHA-256 and metrics) and
   model_card.md, and activate the version.

Same seed ⇒ same dataset ⇒ same metrics (tested).
"""

from __future__ import annotations

import argparse
import json
import platform
import sys
from datetime import UTC, datetime
from pathlib import Path
from typing import Any

import numpy as np
import sklearn
from sklearn.metrics import average_precision_score, roc_auc_score

from .. import rules as rule_engine
from .. import scoring
from ..anomaly import AnomalyModel
from ..features import FEATURE_NAMES, FEATURE_SCHEMA_VERSION, extract_many
from ..registry import MODEL_NAME, ModelIntegrityError, active_version, save
from ..schemas import AnomalyResult, StatisticalResult
from .synthetic import ATTACK_SCENARIOS, Generator, Sample

HYPERPARAMETERS = {"n_estimators": 200, "max_samples": 256, "threshold_percentile": 99.0, "statistical_z": 6.0}
DATASET = {"train_normal": 20_000, "test_normal": 10_000, "test_per_attack": 400}

LIMITATIONS = [
    "Trained and evaluated exclusively on synthetic data generated from hand-specified distributions. "
    "The metrics describe separation of those distributions, not real-world detection accuracy.",
    "The attack scenarios and the detection rules were written by the same developer, so rule recall on the designed scenarios is "
    "optimistic by construction; it shows the rules work as specified, not that they generalise.",
    "Rule thresholds, risk weights and statistical-detector settings were tuned on a separate validation split (generator seed + 2); "
    "the reported test split (seed + 1) was not used for tuning, but all splits come from the same generator.",
    "Normal behaviour differs between organisations; a single global model is used and is not adapted per tenant.",
    "The 'low_and_slow' scenario is deliberately below rule thresholds and close to normal traffic; low recall there is expected and shows a blind spot.",
    "Context windows are fixed (5 min / 15 min / 1 h / 7 days); attacks spread over longer periods are not modelled.",
    "Isolation Forest splits only within the range seen in training, so it cannot express how far beyond that range a value lies; "
    "extreme counts are covered by the robust statistical detector and the rules, not by the forest alone.",
    "Feature contributions are occlusion estimates of this model's behaviour, not causal explanations.",
    "Risk-score weights are documented engineering judgement (kratos_mind/scoring.py), not learned from labelled incidents.",
]


def _rates(predicted: np.ndarray, labels: np.ndarray) -> dict[str, float]:
    tp = int(np.sum(predicted & (labels == 1)))
    fp = int(np.sum(predicted & (labels == 0)))
    fn = int(np.sum(~predicted & (labels == 1)))
    tn = int(np.sum(~predicted & (labels == 0)))
    precision = tp / (tp + fp) if tp + fp else 0.0
    recall = tp / (tp + fn) if tp + fn else 0.0
    f1 = 2 * precision * recall / (precision + recall) if precision + recall else 0.0
    return {
        "precision": round(precision, 4),
        "recall": round(recall, 4),
        "f1": round(f1, 4),
        "false_positive_rate": round(fp / (fp + tn) if fp + tn else 0.0, 4),
        "tp": tp,
        "fp": fp,
        "fn": fn,
        "tn": tn,
    }


def _per_scenario(predicted: np.ndarray, scenarios: np.ndarray) -> dict[str, float]:
    return {s: round(float(predicted[scenarios == s].mean()), 4) for s in ("normal", *ATTACK_SCENARIOS)}


def evaluate(model: AnomalyModel, test: list[Sample]) -> dict[str, Any]:
    labels = np.array([s.label for s in test])
    scenarios = np.array([s.scenario for s in test])
    x = extract_many([(s.event, s.context) for s in test])

    raw = model.raw_scores(x)
    percentiles = model.percentiles(raw)
    anomaly_flag = percentiles >= model.threshold_percentile

    configs = rule_engine.default_configs()
    risks, rule_flag = [], []
    for i, sample in enumerate(test):
        hits = rule_engine.evaluate(sample.event, sample.context, configs)
        rule_flag.append(bool(hits))
        anomaly = AnomalyResult(
            score=float(raw[i]), percentile=float(percentiles[i]), is_anomalous=bool(anomaly_flag[i]), threshold_percentile=model.threshold_percentile, top_features=[]
        )
        statistical: StatisticalResult = model.statistical(x[i], sample.event["event_type"])
        parts = scoring.components(sample.event["severity"], hits, anomaly, statistical, model.statistical_z)
        risks.append(scoring.combine(parts, "medium"))
    risks_arr = np.array(risks)
    rule_arr = np.array(rule_flag)

    return {
        "dataset": "synthetic (see kratos_mind/training/synthetic.py)",
        "test_size": {"normal": int((labels == 0).sum()), "attack": int((labels == 1).sum())},
        "anomaly_model": {
            "roc_auc": round(float(roc_auc_score(labels, raw)), 4),
            "pr_auc": round(float(average_precision_score(labels, raw)), 4),
            "at_threshold": _rates(anomaly_flag, labels),
            "detection_rate_by_scenario": _per_scenario(anomaly_flag, scenarios),
        },
        "rules_only": {
            **_rates(rule_arr, labels),
            "detection_rate_by_scenario": _per_scenario(rule_arr, scenarios),
        },
        "risk_score": {
            "roc_auc": round(float(roc_auc_score(labels, risks_arr)), 4),
            "pr_auc": round(float(average_precision_score(labels, risks_arr)), 4),
            "suspicious_or_worse": {
                **_rates(risks_arr >= scoring.SUSPICIOUS_AT, labels),
                "detection_rate_by_scenario": _per_scenario(risks_arr >= scoring.SUSPICIOUS_AT, scenarios),
            },
            "malicious": {**_rates(risks_arr >= scoring.MALICIOUS_AT, labels), "detection_rate_by_scenario": _per_scenario(risks_arr >= scoring.MALICIOUS_AT, scenarios)},
        },
    }


def model_card(metadata: dict[str, Any]) -> str:
    m = metadata["metrics"]
    am, rs = m["anomaly_model"], m["risk_score"]

    def row(name: str, d: dict[str, Any]) -> str:
        return f"| {name} | {d['precision']:.3f} | {d['recall']:.3f} | {d['f1']:.3f} | {d['false_positive_rate']:.4f} |"

    scen = "\n".join(
        f"| {s} | {am['detection_rate_by_scenario'][s]:.3f} | {m['rules_only']['detection_rate_by_scenario'][s]:.3f} | "
        f"{rs['suspicious_or_worse']['detection_rate_by_scenario'][s]:.3f} |"
        for s in ("normal", *ATTACK_SCENARIOS)
    )
    limitations = "\n".join(f"- {item}" for item in metadata["limitations"])
    return f"""# Model card — {metadata["name"]} {metadata["version"]}

> **Synthetic evaluation.** Every number below was measured on data generated by
> `kratos_mind/training/synthetic.py` (seed {metadata["training_dataset"]["seed"]}).
> It is **not** an estimate of real-world detection accuracy.

| | |
| --- | --- |
| Algorithm | {metadata["algorithm"]} + robust statistical detector (median/MAD) |
| Trained | {metadata["trained_at"]} |
| Feature schema | v{metadata["feature_schema"]["version"]} ({len(metadata["feature_schema"]["features"])} features) |
| Training data | {metadata["training_dataset"]["train_normal"]:,} synthetic normal events |
| Test data | {m["test_size"]["normal"]:,} normal + {m["test_size"]["attack"]:,} attack events (held out) |
| Artifact SHA-256 | `{metadata["artifact_sha256"] if "artifact_sha256" in metadata else "(computed on save)"}` |
| Environment | Python {metadata["environment"]["python"]}, scikit-learn {metadata["environment"]["scikit_learn"]} |

## Results on the synthetic test set

| Detector | ROC-AUC | PR-AUC |
| --- | --- | --- |
| Anomaly model (raw score) | {am["roc_auc"]:.3f} | {am["pr_auc"]:.3f} |
| Complete risk score | {rs["roc_auc"]:.3f} | {rs["pr_auc"]:.3f} |

| Decision | Precision | Recall | F1 | False-positive rate |
| --- | --- | --- | --- | --- |
{row("Anomaly model ≥ p" + str(int(metadata["hyperparameters"]["threshold_percentile"])), am["at_threshold"])}
{row("Rules only (any hit)", m["rules_only"])}
{row("Risk ≥ 40 (suspicious or worse)", rs["suspicious_or_worse"])}
{row("Risk ≥ 70 (malicious)", rs["malicious"])}

### Detection rate by scenario (fraction flagged)

| Scenario | Anomaly model | Rules only | Risk ≥ 40 |
| --- | --- | --- | --- |
{scen}

(`normal` rows are false-positive rates.)

## Intended use

Prioritising security events for human review in KRATOS. Scores are evidence for
an analyst, not automated decisions; KRATOS never takes disruptive action on a
score without human approval.

## Limitations

{limitations}
"""


def train(seed: int, models_dir: Path, activate: bool = True) -> dict[str, Any]:
    gen = Generator(seed)
    train_set = gen.dataset(normal=DATASET["train_normal"])
    test_set = Generator(seed + 1).dataset(normal=DATASET["test_normal"], per_attack=DATASET["test_per_attack"])

    x_train = extract_many([(s.event, s.context) for s in train_set])
    model = AnomalyModel.fit(
        x_train,
        seed=seed,
        event_types=[s.event["event_type"] for s in train_set],
        n_estimators=HYPERPARAMETERS["n_estimators"],
        max_samples=HYPERPARAMETERS["max_samples"],
        threshold_percentile=HYPERPARAMETERS["threshold_percentile"],
        statistical_z=HYPERPARAMETERS["statistical_z"],
    )
    metrics = evaluate(model, test_set)
    version = f"fs{FEATURE_SCHEMA_VERSION}-s{seed}"
    metadata = {
        "algorithm": "IsolationForest",
        "trained_at": datetime.now(UTC).isoformat(timespec="seconds"),
        "feature_schema": {"version": FEATURE_SCHEMA_VERSION, "features": list(FEATURE_NAMES)},
        "hyperparameters": HYPERPARAMETERS,
        "training_dataset": {"synthetic": True, "seed": seed, "generator": "kratos_mind.training.synthetic", **DATASET},
        "metrics": metrics,
        "limitations": LIMITATIONS,
        "environment": {"python": platform.python_version(), "scikit_learn": sklearn.__version__, "numpy": np.__version__},
        "status": "active" if activate else "candidate",
    }
    card = model_card({**metadata, "name": MODEL_NAME, "version": version})
    path = save(models_dir, version, model, metadata, card, activate=activate)
    # Re-render the card now that the artifact hash is known.
    saved = json.loads((path / "metadata.json").read_text())
    (path / "model_card.md").write_text(model_card(saved))
    return saved


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("--seed", type=int, default=42)
    parser.add_argument("--models-dir", type=Path, default=Path(__file__).resolve().parents[2] / "models")
    parser.add_argument("--if-missing", action="store_true", help="exit without training when an active model exists")
    parser.add_argument("--no-activate", action="store_true")
    args = parser.parse_args(argv)

    if args.if_missing:
        try:
            print(f"Active model present ({active_version(args.models_dir)}); skipping training.")
            return 0
        except ModelIntegrityError:
            pass

    metadata = train(args.seed, args.models_dir, activate=not args.no_activate)
    summary = {
        "version": metadata["version"],
        "artifact_sha256": metadata["artifact_sha256"],
        "anomaly_roc_auc": metadata["metrics"]["anomaly_model"]["roc_auc"],
        "risk_roc_auc": metadata["metrics"]["risk_score"]["roc_auc"],
        "risk_suspicious": {k: metadata["metrics"]["risk_score"]["suspicious_or_worse"][k] for k in ("precision", "recall", "false_positive_rate")},
    }
    json.dump(summary, sys.stdout, indent=2)
    print()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

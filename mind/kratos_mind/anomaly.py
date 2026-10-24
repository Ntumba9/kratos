"""Anomaly detection: Isolation Forest + robust statistical detector.

Isolation Forest
    Trained on synthetic *normal* traffic only (semi-supervised). Raw scores
    are converted to a percentile of the training-normal score distribution, so
    "99.5" means "more anomalous than 99.5 % of normal traffic seen in training".

Explanations (occlusion)
    For each feature, replace the event's value with the training median and
    re-score. The drop in anomaly score is that feature's contribution. With 13
    features this is 13 extra predictions — cheap, model-agnostic, and easy to
    reason about. It explains *this model's* behaviour, not ground truth.

Statistical detector
    Robust z-scores (median / MAD) of count features against training-normal
    traffic *of the same event type* (falling back to all types when a type had
    fewer than MIN_TYPE_SAMPLES training events). Conditioning matters: 300
    requests is normal for an API event and extreme for a login. Independent of
    the forest, it catches single extreme values the forest cannot rank.
"""

from __future__ import annotations

from dataclasses import dataclass, field

import numpy as np
from sklearn.ensemble import IsolationForest

from .features import COUNT_FEATURES, FEATURE_DESCRIPTIONS, FEATURE_NAMES
from .schemas import AnomalyResult, FeatureContribution, StatisticalResult

MAD_TO_SIGMA = 1.4826
MIN_TYPE_SAMPLES = 50
MAD_FLOOR = 0.5  # in log space: a count must be several times its typical value to stand out


@dataclass
class AnomalyModel:
    forest: IsolationForest
    medians: np.ndarray
    mads: np.ndarray
    # Sorted anomaly scores of the training-normal set (for percentile calibration).
    reference_scores: np.ndarray
    threshold_percentile: float = 99.0
    statistical_z: float = 6.0
    feature_names: tuple[str, ...] = field(default=FEATURE_NAMES)
    # event_type -> (medians, mads) over all features, from training-normal data
    type_baselines: dict[str, tuple[np.ndarray, np.ndarray]] = field(default_factory=dict)

    # ------------------------------------------------------------------ build

    @classmethod
    def fit(
        cls,
        normal: np.ndarray,
        *,
        seed: int,
        event_types: list[str] | None = None,
        n_estimators: int = 200,
        max_samples: int = 256,
        threshold_percentile: float = 99.0,
        statistical_z: float = 6.0,
    ) -> AnomalyModel:
        forest = IsolationForest(
            n_estimators=n_estimators,
            max_samples=min(max_samples, len(normal)),
            contamination="auto",
            random_state=seed,
            n_jobs=1,  # deterministic and container-friendly
        )
        forest.fit(normal)
        medians, mads = _robust(normal)
        reference = np.sort(-forest.score_samples(normal))

        baselines: dict[str, tuple[np.ndarray, np.ndarray]] = {}
        if event_types is not None:
            types = np.asarray(event_types)
            for event_type in sorted(set(event_types)):
                rows = normal[types == event_type]
                if len(rows) >= MIN_TYPE_SAMPLES:
                    baselines[event_type] = _robust(rows)

        return cls(forest, medians, mads, reference, threshold_percentile, statistical_z, FEATURE_NAMES, baselines)

    # ---------------------------------------------------------------- scoring

    def raw_scores(self, x: np.ndarray) -> np.ndarray:
        """Higher = more anomalous."""
        return -self.forest.score_samples(np.atleast_2d(x))

    def percentiles(self, scores: np.ndarray) -> np.ndarray:
        ranks = np.searchsorted(self.reference_scores, scores, side="right")
        return 100.0 * ranks / len(self.reference_scores)

    def analyse(self, x: np.ndarray, top: int = 3) -> AnomalyResult:
        score = float(self.raw_scores(x)[0])
        percentile = float(self.percentiles(np.array([score]))[0])
        return AnomalyResult(
            score=round(score, 5),
            percentile=round(percentile, 2),
            is_anomalous=percentile >= self.threshold_percentile,
            threshold_percentile=self.threshold_percentile,
            top_features=self.explain(x, score, top),
        )

    def explain(self, x: np.ndarray, score: float, top: int = 3) -> list[FeatureContribution]:
        occluded = np.repeat(np.atleast_2d(x), len(self.feature_names), axis=0)
        occluded[np.arange(len(self.feature_names)), np.arange(len(self.feature_names))] = self.medians
        deltas = score - self.raw_scores(occluded)
        order = np.argsort(deltas)[::-1]
        out = []
        for i in order[:top]:
            if deltas[i] <= 0:
                break
            name = self.feature_names[i]
            out.append(
                FeatureContribution(
                    feature=name,
                    description=FEATURE_DESCRIPTIONS[name],
                    value=round(float(x[i]), 4),
                    typical=round(float(self.medians[i]), 4),
                    contribution=round(float(deltas[i]), 5),
                )
            )
        return out

    def statistical(self, x: np.ndarray, event_type: str | None = None) -> StatisticalResult:
        medians, mads = self.type_baselines.get(event_type or "", (self.medians, self.mads))
        flagged: list[FeatureContribution] = []
        max_z = 0.0
        for name in COUNT_FEATURES:
            i = self.feature_names.index(name)
            scale = max(float(mads[i]), MAD_FLOOR)  # many count features have MAD 0
            z = (float(x[i]) - float(medians[i])) / scale
            max_z = max(max_z, z)
            if z >= self.statistical_z:
                flagged.append(
                    FeatureContribution(
                        feature=name,
                        description=FEATURE_DESCRIPTIONS[name],
                        value=round(float(x[i]), 4),
                        typical=round(float(self.medians[i]), 4),
                        contribution=round(z, 2),
                    )
                )
        flagged.sort(key=lambda f: f.contribution, reverse=True)
        return StatisticalResult(flagged=flagged, max_robust_z=round(max_z, 2))


def _robust(rows: np.ndarray) -> tuple[np.ndarray, np.ndarray]:
    medians = np.median(rows, axis=0)
    return medians, np.median(np.abs(rows - medians), axis=0) * MAD_TO_SIGMA

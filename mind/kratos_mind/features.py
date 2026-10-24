"""Feature extraction — the single implementation used by both training and serving.

Changing a feature (name, order, transform) requires bumping
``FEATURE_SCHEMA_VERSION`` and retraining; the model metadata records the
schema version it was trained with, and the service refuses a mismatch.
"""

from __future__ import annotations

import math
from collections.abc import Mapping
from dataclasses import dataclass
from datetime import datetime
from typing import Any

import numpy as np

from .schemas import SEVERITY_RANK

FEATURE_SCHEMA_VERSION = "2"  # v2: asset activity is neutral without an established baseline

# Below this 7-day hourly mean an asset has no meaningful baseline (new or
# rarely active), so its activity z-score is undefined and set to neutral.
MIN_BASELINE_MEAN = 0.5

# Numeric metadata fields that express "how much" (attempts, requests, bytes).
MAGNITUDE_FIELDS = ("failed_attempts", "request_count", "ports_scanned", "bytes_transferred")


@dataclass(frozen=True)
class FeatureSpec:
    name: str
    description: str


FEATURES: tuple[FeatureSpec, ...] = (
    FeatureSpec("log_src_ip_events_5m", "events from this source IP in the last 5 minutes"),
    FeatureSpec("log_src_ip_auth_failures_15m", "failed logins from this source IP in 15 minutes"),
    FeatureSpec("log_src_ip_distinct_users_1h", "distinct usernames tried from this source IP in 1 hour"),
    FeatureSpec("log_user_auth_failures_15m", "failed logins for this username in 15 minutes"),
    FeatureSpec("log_user_permission_denied_1h", "permission denials for this username in 1 hour"),
    FeatureSpec("asset_activity_z", "asset activity this hour vs its 7-day hourly baseline (z)"),
    FeatureSpec("type_rarity", "rarity of this event type in the organisation (7 days)"),
    FeatureSpec("type_hour_rarity", "rarity of this event type at this hour of day (7 days)"),
    FeatureSpec("hour_sin", "time of day (sine component)"),
    FeatureSpec("hour_cos", "time of day (cosine component)"),
    FeatureSpec("is_weekend", "occurred on a weekend (UTC)"),
    FeatureSpec("severity_rank", "reported severity (0 = info … 4 = critical)"),
    FeatureSpec("log_magnitude", "magnitude reported by the event (attempts, requests, bytes)"),
)

FEATURE_NAMES: tuple[str, ...] = tuple(f.name for f in FEATURES)
FEATURE_DESCRIPTIONS: dict[str, str] = {f.name: f.description for f in FEATURES}

# Count-like features where a large positive deviation is meaningful for the
# statistical detector (cyclic/time features are excluded).
COUNT_FEATURES: tuple[str, ...] = (
    "log_src_ip_events_5m",
    "log_src_ip_auth_failures_15m",
    "log_src_ip_distinct_users_1h",
    "log_user_auth_failures_15m",
    "log_user_permission_denied_1h",
    "asset_activity_z",
    "log_magnitude",
)

_RARITY_FLOOR = 1e-4  # => rarity ∈ [0, 4]


def _rarity(share: float) -> float:
    return float(-math.log10(min(1.0, max(0.0, share)) + _RARITY_FLOOR))


def _magnitude(metadata: Mapping[str, Any]) -> float:
    values = [metadata.get(k) for k in MAGNITUDE_FIELDS]
    numeric = [float(v) for v in values if isinstance(v, (int, float)) and not isinstance(v, bool) and v >= 0]
    return math.log1p(max(numeric)) if numeric else 0.0


def extract(event: Mapping[str, Any], context: Mapping[str, Any]) -> np.ndarray:
    """Turn one (event, context) pair into the model's feature vector."""
    occurred: datetime = event["occurred_at"]
    if isinstance(occurred, str):
        occurred = datetime.fromisoformat(occurred.replace("Z", "+00:00"))
    hour = occurred.hour + occurred.minute / 60.0

    if float(context["asset_hourly_mean_7d"]) < MIN_BASELINE_MEAN:
        asset_z = 0.0  # cold start: no baseline to deviate from
    else:
        std = max(float(context["asset_hourly_std_7d"]), 1.0)
        asset_z = (float(context["asset_events_1h"]) - float(context["asset_hourly_mean_7d"])) / std

    vector = (
        math.log1p(context["src_ip_events_5m"]),
        math.log1p(context["src_ip_auth_failures_15m"]),
        math.log1p(context["src_ip_distinct_users_1h"]),
        math.log1p(context["user_auth_failures_15m"]),
        math.log1p(context["user_permission_denied_1h"]),
        float(np.clip(asset_z, -5.0, 20.0)),
        _rarity(context["type_share_7d"]),
        _rarity(context["type_hour_share_7d"]),
        math.sin(2 * math.pi * hour / 24.0),
        math.cos(2 * math.pi * hour / 24.0),
        1.0 if occurred.weekday() >= 5 else 0.0,
        float(SEVERITY_RANK[event["severity"]]),
        _magnitude(event.get("metadata") or {}),
    )
    return np.asarray(vector, dtype=np.float64)


def extract_many(rows: list[tuple[Mapping[str, Any], Mapping[str, Any]]]) -> np.ndarray:
    return np.vstack([extract(e, c) for e, c in rows]) if rows else np.empty((0, len(FEATURES)))

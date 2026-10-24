"""Request/response contract for POST /internal/v1/analyse.

CORE (PHP) builds the request; these models validate it strictly
(``extra="forbid"``) so a contract drift fails loudly instead of silently
producing wrong scores. The shared example in ``contracts/mind/`` is checked
by tests on both sides.
"""

from __future__ import annotations

from datetime import datetime
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field

Severity = Literal["info", "low", "medium", "high", "critical"]
Criticality = Literal["low", "medium", "high", "critical"]
Verdict = Literal["benign", "suspicious", "malicious"]

SEVERITY_RANK: dict[str, int] = {"info": 0, "low": 1, "medium": 2, "high": 3, "critical": 4}


class Strict(BaseModel):
    model_config = ConfigDict(extra="forbid", frozen=True)


class EventIn(Strict):
    id: str = Field(min_length=1, max_length=64)
    event_type: str = Field(min_length=1, max_length=64)
    source: str = Field(min_length=1, max_length=128)
    severity: Severity
    occurred_at: datetime
    source_ip: str | None = None
    metadata: dict[str, Any] = Field(default_factory=dict)


class ContextIn(Strict):
    """Tenant-scoped history computed by CORE (one SQL query per event).

    Windows end at the event's ``occurred_at`` and include the event itself.
    """

    src_ip_events_5m: int = Field(ge=0)
    src_ip_auth_failures_15m: int = Field(ge=0)
    src_ip_distinct_users_1h: int = Field(ge=0)
    user_auth_failures_15m: int = Field(ge=0)
    user_permission_denied_1h: int = Field(ge=0)
    asset_events_1h: int = Field(ge=0)
    asset_hourly_mean_7d: float = Field(ge=0)
    asset_hourly_std_7d: float = Field(ge=0)
    type_share_7d: float = Field(ge=0, le=1)
    type_hour_share_7d: float = Field(ge=0, le=1)
    org_events_7d: int = Field(ge=0)


class AssetIn(Strict):
    name: str
    criticality: Criticality


class RuleConfig(Strict):
    key: str = Field(pattern=r"^[a-z][a-z0-9_]{2,63}$")
    enabled: bool = True
    severity: Severity
    parameters: dict[str, float | int] = Field(default_factory=dict)


class AnalyseRequest(Strict):
    event: EventIn
    context: ContextIn
    asset: AssetIn | None = None
    rules: list[RuleConfig] = Field(default_factory=list, max_length=64)


class RuleHit(BaseModel):
    key: str
    severity: Severity
    score: float = Field(ge=0, le=100)
    category: str
    explanation: str
    observed: dict[str, float | int | str]


class FeatureContribution(BaseModel):
    feature: str
    description: str
    value: float
    typical: float
    contribution: float


class AnomalyResult(BaseModel):
    score: float
    percentile: float = Field(ge=0, le=100)
    is_anomalous: bool
    threshold_percentile: float
    top_features: list[FeatureContribution]


class StatisticalResult(BaseModel):
    flagged: list[FeatureContribution]
    max_robust_z: float


class ModelInfo(BaseModel):
    name: str
    version: str
    algorithm: str
    artifact_sha256: str
    feature_schema_version: str


class AnalyseResponse(BaseModel):
    event_id: str
    risk_score: float = Field(ge=0, le=100)
    verdict: Verdict
    category: str
    rule_hits: list[RuleHit]
    anomaly: AnomalyResult
    statistical: StatisticalResult
    risk_components: dict[str, float]
    summary: str
    model: ModelInfo

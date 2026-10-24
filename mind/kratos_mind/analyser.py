"""Single entry point that turns an AnalyseRequest into an AnalyseResponse.

Pure and synchronous: the same function is used by the API and by offline
evaluation, so the published metrics describe the code that actually serves.
"""

from __future__ import annotations

from typing import Any

from . import rules as rule_engine
from . import scoring
from .features import FEATURE_SCHEMA_VERSION, extract
from .registry import LoadedModel
from .schemas import AnalyseRequest, AnalyseResponse, ModelInfo


def analyse(request: AnalyseRequest, loaded: LoadedModel) -> AnalyseResponse:
    event: dict[str, Any] = request.event.model_dump()
    context: dict[str, Any] = request.context.model_dump()

    hits = rule_engine.evaluate(event, context, request.rules)
    vector = extract(event, context)
    anomaly = loaded.model.analyse(vector)
    statistical = loaded.model.statistical(vector, event["event_type"])

    parts = scoring.components(event["severity"], hits, anomaly, statistical, loaded.model.statistical_z)
    risk = scoring.combine(parts, request.asset.criticality if request.asset else None)
    verdict = scoring.verdict(risk)

    return AnalyseResponse(
        event_id=request.event.id,
        risk_score=risk,
        verdict=verdict,
        category=scoring.category(event["event_type"], hits, anomaly, risk),
        rule_hits=hits,
        anomaly=anomaly,
        statistical=statistical,
        risk_components=parts,
        summary=scoring.summarise(risk, verdict, hits, anomaly, statistical, parts, event["severity"], request.asset.criticality if request.asset else None),
        model=ModelInfo(
            name=loaded.metadata["name"],
            version=loaded.version,
            algorithm=loaded.metadata["algorithm"],
            artifact_sha256=loaded.metadata["artifact_sha256"],
            feature_schema_version=FEATURE_SCHEMA_VERSION,
        ),
    )

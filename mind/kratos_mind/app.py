"""KRATOS MIND — internal intelligence API.

Endpoints
    GET  /health                    liveness/readiness (no secrets, no auth)
    POST /internal/v1/analyse       score one event       (HMAC-signed by CORE)
    GET  /internal/v1/models/active active model metadata (HMAC-signed by CORE)

Not exposed through the edge proxy; reachable only on the internal network.
"""

from __future__ import annotations

import logging
import time
import uuid
from typing import Any

from fastapi import Depends, FastAPI, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from pydantic import ValidationError
from starlette.exceptions import HTTPException as StarletteHTTPException

from . import __version__, logs
from .analyser import analyse
from .config import Settings
from .registry import LoadedModel, load
from .schemas import AnalyseRequest, AnalyseResponse
from .security import ServiceAuth

log = logging.getLogger("kratos_mind")

MAX_BODY_BYTES = 65_536


def create_app(settings: Settings | None = None, loaded: LoadedModel | None = None) -> FastAPI:
    settings = settings or Settings.from_env()
    logs.configure(settings.log_level)
    loaded = loaded or load(settings.models_dir, settings.model_version)
    auth = ServiceAuth(settings.shared_secret, settings.signature_tolerance)

    app = FastAPI(title="KRATOS MIND", version=__version__, docs_url=None, redoc_url=None, openapi_url=None)
    app.state.model = loaded
    log.info("model.loaded", extra={"context": {"version": loaded.version, "sha256": loaded.metadata["artifact_sha256"]}})

    @app.middleware("http")
    async def correlate(request: Request, call_next):  # type: ignore[no-untyped-def]
        inbound = request.headers.get("x-request-id", "")
        rid = inbound if 8 <= len(inbound) <= 64 and inbound.replace("-", "").isalnum() else str(uuid.uuid4())
        token = logs.request_id.set(rid)
        started = time.perf_counter()
        try:
            if int(request.headers.get("content-length") or 0) > MAX_BODY_BYTES:
                return _error(413, "payload_too_large", "Request body too large.", rid)
            response = await call_next(request)
        finally:
            logs.request_id.reset(token)
        response.headers["X-Request-Id"] = rid
        response.headers["Cache-Control"] = "no-store"
        if request.url.path != "/health":
            log.info(
                "http.request",
                extra={
                    "context": {
                        "method": request.method,
                        "path": request.url.path,
                        "status": response.status_code,
                        "duration_ms": round((time.perf_counter() - started) * 1000, 2),
                        "request_id": rid,
                    }
                },
            )
        return response

    @app.exception_handler(StarletteHTTPException)
    async def http_error(request: Request, exc: StarletteHTTPException) -> JSONResponse:
        detail = exc.detail if isinstance(exc.detail, dict) else {"code": "http_error", "message": str(exc.detail)}
        return _error(exc.status_code, detail.get("code", "http_error"), detail.get("message", ""), None)

    @app.exception_handler(RequestValidationError)
    async def validation_error(request: Request, exc: RequestValidationError) -> JSONResponse:
        errors = [{"loc": ".".join(str(p) for p in e["loc"]), "msg": e["msg"]} for e in exc.errors()]
        return _error(422, "contract_violation", "The analysis request does not match the contract.", None, errors)

    @app.get("/health")
    def health() -> dict[str, Any]:
        model: LoadedModel = app.state.model
        return {"status": "ok", "service": "kratos-mind", "version": __version__, "model": model.version}

    @app.post("/internal/v1/analyse", response_model=AnalyseResponse)
    async def analyse_event(request: Request, _service: str = Depends(auth)) -> AnalyseResponse:
        # The body is parsed only after authentication succeeded, so
        # unauthenticated callers never see contract/validation details.
        try:
            payload = AnalyseRequest.model_validate_json(await request.body())
        except ValidationError as exc:
            raise RequestValidationError([{**e, "loc": ("body", *e["loc"])} for e in exc.errors()]) from exc
        started = time.perf_counter()
        result = analyse(payload, app.state.model)
        log.info(
            "analyse.completed",
            extra={
                "context": {
                    "event_id": payload.event.id,
                    "risk": result.risk_score,
                    "verdict": result.verdict,
                    "rules": [h.key for h in result.rule_hits],
                    "anomaly_percentile": result.anomaly.percentile,
                    "duration_ms": round((time.perf_counter() - started) * 1000, 2),
                }
            },
        )
        return result

    @app.get("/internal/v1/models/active", dependencies=[Depends(auth)])
    def active_model() -> dict[str, Any]:
        model: LoadedModel = app.state.model
        card = (model.path / "model_card.md").read_text() if (model.path / "model_card.md").exists() else None
        return {"metadata": model.metadata, "model_card": card}

    return app


def _error(status: int, code: str, message: str, rid: str | None, details: Any = None) -> JSONResponse:
    body: dict[str, Any] = {"error": {"code": code, "message": message}, "request_id": rid or logs.request_id.get()}
    if details is not None:
        body["error"]["details"] = details
    return JSONResponse(body, status_code=status)

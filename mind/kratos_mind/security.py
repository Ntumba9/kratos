"""Service-to-service authentication (ADR-0006 scheme, ADR-0007).

CORE signs every request:

    X-Kratos-Service:   core
    X-Kratos-Timestamp: <unix seconds>
    X-Kratos-Signature: v1=<hex HMAC-SHA256(secret, METHOD\\nPATH\\nTIMESTAMP\\nhex(SHA-256(body)))>

Failures return the same 401 so callers learn nothing about which check failed.
"""

from __future__ import annotations

import hashlib
import hmac
import time

from fastapi import HTTPException, Request

SIGNATURE_VERSION = "v1"


def canonical(method: str, path: str, timestamp: str, body: bytes) -> str:
    return f"{method.upper()}\n{path}\n{timestamp}\n{hashlib.sha256(body).hexdigest()}"


def sign(secret: str, method: str, path: str, timestamp: str, body: bytes) -> str:
    mac = hmac.new(secret.encode(), canonical(method, path, timestamp, body).encode(), hashlib.sha256)
    return f"{SIGNATURE_VERSION}={mac.hexdigest()}"


def verify(secret: str, method: str, path: str, timestamp: str, body: bytes, signature: str, *, tolerance: int, now: float | None = None) -> bool:
    if not timestamp.isdigit():
        return False
    if abs((now if now is not None else time.time()) - int(timestamp)) > tolerance:
        return False
    return hmac.compare_digest(sign(secret, method, path, timestamp, body), signature)


class ServiceAuth:
    """FastAPI dependency enforcing signed requests from CORE."""

    def __init__(self, secret: str, tolerance: int = 300, allowed_services: frozenset[str] = frozenset({"core"})):
        if len(secret) < 32:
            raise ValueError("MIND_SHARED_SECRET must be at least 32 characters.")
        self._secret = secret
        self._tolerance = tolerance
        self._allowed = allowed_services

    async def __call__(self, request: Request) -> str:
        service = request.headers.get("x-kratos-service", "")
        timestamp = request.headers.get("x-kratos-timestamp", "")
        signature = request.headers.get("x-kratos-signature", "")
        body = await request.body()

        if service not in self._allowed or not verify(self._secret, request.method, request.url.path, timestamp, body, signature, tolerance=self._tolerance):
            raise HTTPException(status_code=401, detail={"code": "invalid_signature", "message": "Service authentication failed."})
        return service

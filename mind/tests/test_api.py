"""HTTP API: authentication, contract enforcement, responses."""

from __future__ import annotations

import json
import time

import pytest
from fastapi.testclient import TestClient

from kratos_mind.app import create_app
from kratos_mind.config import Settings
from kratos_mind.security import sign

from .conftest import SECRET

PATH = "/internal/v1/analyse"


@pytest.fixture()
def client(models_dir, loaded) -> TestClient:
    settings = Settings(shared_secret=SECRET, models_dir=models_dir, model_version=None, signature_tolerance=300, log_level="WARNING")
    return TestClient(create_app(settings, loaded))


def signed(body: bytes, *, secret: str = SECRET, timestamp: int | None = None, path: str = PATH, method: str = "POST", service: str = "core") -> dict[str, str]:
    ts = str(timestamp if timestamp is not None else int(time.time()))
    return {
        "Content-Type": "application/json",
        "X-Kratos-Service": service,
        "X-Kratos-Timestamp": ts,
        "X-Kratos-Signature": sign(secret, method, path, ts, body),
        "X-Request-Id": "test-request-0001",
    }


def test_health_is_public_and_reveals_no_secrets(client) -> None:
    response = client.get("/health")
    assert response.status_code == 200
    assert response.json()["status"] == "ok"
    assert SECRET not in response.text


def test_signed_request_is_analysed(client, contract_example) -> None:
    body = json.dumps(contract_example).encode()
    response = client.post(PATH, content=body, headers=signed(body))

    assert response.status_code == 200, response.text
    data = response.json()
    assert data["event_id"] == contract_example["event"]["id"]
    assert data["verdict"] == "malicious"
    assert response.headers["X-Request-Id"] == "test-request-0001"


@pytest.mark.parametrize("variant", ["wrong_secret", "stale", "future", "bad_service", "missing", "tampered"])
def test_unauthenticated_requests_are_rejected(client, contract_example, variant: str) -> None:
    body = json.dumps(contract_example).encode()
    headers = {
        "wrong_secret": signed(body, secret="y" * 40),
        "stale": signed(body, timestamp=int(time.time()) - 301),
        "future": signed(body, timestamp=int(time.time()) + 301),
        "bad_service": signed(body, service="nginx"),
        "missing": {"Content-Type": "application/json"},
        "tampered": signed(body),
    }[variant]
    sent = body.replace(b'"medium"', b'"low"') if variant == "tampered" else body

    response = client.post(PATH, content=sent, headers=headers)

    assert response.status_code == 401
    assert response.json()["error"]["code"] == "invalid_signature"


def test_contract_violations_are_rejected_with_details(client, contract_example) -> None:
    broken = {**contract_example, "unexpected": True}
    broken["context"] = {**contract_example["context"], "src_ip_events_5m": -1}
    body = json.dumps(broken).encode()

    response = client.post(PATH, content=body, headers=signed(body))

    assert response.status_code == 422
    error = response.json()["error"]
    assert error["code"] == "contract_violation"
    locations = {d["loc"] for d in error["details"]}
    assert "body.unexpected" in locations and "body.context.src_ip_events_5m" in locations


def test_authentication_is_checked_before_the_body_is_parsed(client) -> None:
    response = client.post(PATH, content=b"{not json", headers={"Content-Type": "application/json"})
    assert response.status_code == 401


def test_model_endpoint_requires_auth_and_returns_the_card(client) -> None:
    assert client.get("/internal/v1/models/active").status_code == 401

    headers = signed(b"", path="/internal/v1/models/active", method="GET")
    response = client.get("/internal/v1/models/active", headers=headers)

    assert response.status_code == 200
    assert response.json()["metadata"]["training_dataset"]["synthetic"] is True
    assert "Synthetic evaluation" in response.json()["model_card"]


def test_oversized_bodies_are_rejected(client) -> None:
    body = b"{" + b" " * 70_000 + b"}"
    response = client.post(PATH, content=body, headers=signed(body))
    assert response.status_code == 413


def test_short_secrets_are_refused_at_startup(models_dir, loaded) -> None:
    with pytest.raises(ValueError, match="at least 32"):
        create_app(Settings(shared_secret="short", models_dir=models_dir, model_version=None, signature_tolerance=300, log_level="WARNING"), loaded)

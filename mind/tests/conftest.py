from __future__ import annotations

import json
from pathlib import Path

import pytest

from kratos_mind.registry import load
from kratos_mind.training import train as training

CONTRACT = Path(__file__).resolve().parents[1].parent / "contracts" / "mind" / "analyse-request.example.json"
if not CONTRACT.exists():  # inside the container image the contracts dir sits next to tests/
    CONTRACT = Path(__file__).resolve().parents[1] / "contracts" / "mind" / "analyse-request.example.json"

SECRET = "test-secret-" + "x" * 32


@pytest.fixture(scope="session")
def models_dir(tmp_path_factory: pytest.TempPathFactory) -> Path:
    """Train once per test session with a reduced dataset (fast, still seeded)."""
    directory = tmp_path_factory.mktemp("models")
    original = dict(training.DATASET)
    training.DATASET.update(train_normal=4000, test_normal=2000, test_per_attack=60)
    try:
        training.train(seed=7, models_dir=directory)
    finally:
        training.DATASET.clear()
        training.DATASET.update(original)
    return directory


@pytest.fixture(scope="session")
def loaded(models_dir: Path):
    return load(models_dir)


@pytest.fixture()
def contract_example() -> dict:
    return json.loads(CONTRACT.read_text())

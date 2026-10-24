"""Model registry on disk.

    models/
      registry.json                 {"active": {"isolation_forest": "2026.10.24-s42"}}
      isolation_forest/<version>/
        model.joblib                AnomalyModel (pickle — load only our own artifacts)
        metadata.json               sha256, feature schema, hyperparameters, dataset, metrics, limitations
        model_card.md               human-readable summary

The artifact's SHA-256 must match metadata.json, and the feature schema must
match the running code; otherwise the service refuses to start.
"""

from __future__ import annotations

import hashlib
import json
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import joblib

from .anomaly import AnomalyModel
from .features import FEATURE_NAMES, FEATURE_SCHEMA_VERSION

MODEL_NAME = "isolation_forest"


class ModelIntegrityError(RuntimeError):
    pass


@dataclass(frozen=True)
class LoadedModel:
    model: AnomalyModel
    metadata: dict[str, Any]
    path: Path

    @property
    def version(self) -> str:
        return str(self.metadata["version"])


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(65536), b""):
            digest.update(chunk)
    return digest.hexdigest()


def active_version(models_dir: Path, name: str = MODEL_NAME) -> str:
    registry = models_dir / "registry.json"
    if not registry.exists():
        raise ModelIntegrityError(f"No model registry at {registry}. Run: python -m kratos_mind.training.train")
    return str(json.loads(registry.read_text())["active"][name])


def load(models_dir: Path, version: str | None = None, name: str = MODEL_NAME) -> LoadedModel:
    version = version or active_version(models_dir, name)
    directory = models_dir / name / version
    metadata = json.loads((directory / "metadata.json").read_text())
    artifact = directory / "model.joblib"

    actual = sha256_file(artifact)
    if actual != metadata["artifact_sha256"]:
        raise ModelIntegrityError(f"Artifact hash mismatch for {name}/{version}: {actual} != {metadata['artifact_sha256']}")
    if metadata["feature_schema"]["version"] != FEATURE_SCHEMA_VERSION or tuple(metadata["feature_schema"]["features"]) != FEATURE_NAMES:
        raise ModelIntegrityError(f"Model {name}/{version} was trained with a different feature schema; retrain.")

    model = joblib.load(artifact)
    if not isinstance(model, AnomalyModel):
        raise ModelIntegrityError("Artifact is not an AnomalyModel.")
    return LoadedModel(model=model, metadata=metadata, path=directory)


def save(models_dir: Path, version: str, model: AnomalyModel, metadata: dict[str, Any], card: str, activate: bool = True, name: str = MODEL_NAME) -> Path:
    directory = models_dir / name / version
    directory.mkdir(parents=True, exist_ok=True)
    artifact = directory / "model.joblib"
    joblib.dump(model, artifact)
    metadata = {**metadata, "name": name, "version": version, "artifact_sha256": sha256_file(artifact)}
    (directory / "metadata.json").write_text(json.dumps(metadata, indent=2, sort_keys=True))
    (directory / "model_card.md").write_text(card)

    if activate:
        registry_path = models_dir / "registry.json"
        registry = json.loads(registry_path.read_text()) if registry_path.exists() else {"active": {}}
        registry["active"][name] = version
        registry_path.write_text(json.dumps(registry, indent=2, sort_keys=True))
    return directory

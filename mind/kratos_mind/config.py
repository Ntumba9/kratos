"""Configuration from environment variables (no secrets in code or images)."""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


@dataclass(frozen=True)
class Settings:
    shared_secret: str
    models_dir: Path
    model_version: str | None
    signature_tolerance: int
    log_level: str

    @classmethod
    def from_env(cls) -> Settings:
        secret = os.environ.get("MIND_SHARED_SECRET", "")
        if not secret:
            raise RuntimeError("MIND_SHARED_SECRET is not set.")
        return cls(
            shared_secret=secret,
            models_dir=Path(os.environ.get("MIND_MODELS_DIR", Path(__file__).resolve().parent.parent / "models")),
            model_version=os.environ.get("MIND_MODEL_VERSION") or None,  # default: registry's active version
            signature_tolerance=int(os.environ.get("MIND_SIGNATURE_TOLERANCE", "300")),
            log_level=os.environ.get("MIND_LOG_LEVEL", "INFO"),
        )

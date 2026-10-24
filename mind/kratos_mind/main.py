"""ASGI entry point: ``uvicorn kratos_mind.main:app``."""

from .app import create_app

app = create_app()

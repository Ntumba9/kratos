# syntax=docker/dockerfile:1.7
# KRATOS MIND - internal intelligence service.
# Build context: repository root (the shared contracts/ directory is needed by tests).
FROM python:3.12-slim AS base

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 PIP_NO_CACHE_DIR=1 PIP_DISABLE_PIP_VERSION_CHECK=1
WORKDIR /srv/mind

RUN groupadd --system mind && useradd --system --gid mind --home /srv/mind mind

COPY mind/requirements.lock ./
RUN pip install -r requirements.lock

COPY mind/pyproject.toml ./
COPY mind/kratos_mind ./kratos_mind

# ------------------------------------------------------------------- dev
FROM base AS dev
COPY mind/requirements-dev.lock ./
RUN pip install -r requirements-dev.lock
COPY mind/tests ./tests
COPY contracts ./contracts
RUN mkdir -p /srv/mind/models && chown mind:mind /srv/mind/models
USER mind
# Trains the seeded model on first start if the models volume is empty.
CMD ["sh", "-c", "python -m kratos_mind.training.train --if-missing && exec uvicorn kratos_mind.main:app --host 0.0.0.0 --port 8000 --no-server-header"]

# ------------------------------------------------------------------ prod
FROM base AS prod
# The model is trained reproducibly at build time (seeded synthetic data);
# its SHA-256 is recorded and verified on every start.
RUN python -m kratos_mind.training.train --seed 42 --models-dir /srv/mind/models && chown -R mind:mind /srv/mind/models
USER mind
EXPOSE 8000
HEALTHCHECK --interval=15s --timeout=3s --retries=5 CMD python -c "import urllib.request,sys; sys.exit(0 if urllib.request.urlopen('http://127.0.0.1:8000/health', timeout=2).status == 200 else 1)"
CMD ["uvicorn", "kratos_mind.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "2", "--no-server-header"]

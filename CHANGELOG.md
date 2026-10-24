# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added — Phase 3 (KRATOS MIND: detection, anomaly detection, risk scoring)

- `mind/` FastAPI service (Python 3.12) with HMAC service authentication,
  health endpoint and structured JSON logs. It runs on an internal-only Docker
  network.
- Feature extraction (13 features, versioned schema v2) shared by training and
  serving.
- 10 deterministic rules, each scored within bounds set by its severity and
  explained with the observed value and the threshold.
- Isolation Forest with percentile calibration and occlusion-based feature
  explanations, plus a robust per-event-type statistical detector.
- Noisy-OR risk score (0–100), verdict (benign / suspicious / malicious),
  threat category and a deterministic summary.
- Seeded synthetic dataset generator, plus a training and evaluation CLI that
  writes model metadata (SHA-256, metrics, limitations). MIND verifies the
  checksum and feature schema when it loads a model.
- CORE: `detection_rules`, `model_versions`, `detection_results` tables and
  analysis columns on `security_events`; context builder (one SQL query);
  signed MIND client; analysis in the processing pipeline; outage retry
  without dead-lettering (ADR-0007).
- CORE: `kratos:events:reanalyse` command to re-score recent events.
- API: detection rules (list, tune within bounds, reset; audited), detections,
  intelligence statistics, active model card, verdict and minimum-risk filters,
  risk sorting, `mind` health check.
- Dashboard: Threat Intelligence page (verdict tiles, anomaly trend, risk
  histogram, rule hits, latest detections, rule tuning, model card labelled as
  a synthetic evaluation). The event explorer gains a risk column, verdict
  filter, risk sort, `?event=` deep link and the analysis evidence in the
  drawer.
- Tests: pytest suite (41), CORE ⇄ MIND contract fixtures shared by PHPUnit
  and pytest, a real-service integration suite, and Vitest and Playwright
  coverage of the intelligence flow.
- CI: MIND lint, tests and reproducible training; MIND image build;
  integration suite in the end-to-end job.

### Fixed — found during Phase 3 development and verification

- MIND returned `422` before `401`, which exposed the request schema to
  unauthenticated callers. Authentication now runs before the body is parsed.
- High false-positive rate (11.8 % at risk ≥ 40 on the validation split).
  Fixed with per-type statistical baselines, an anomaly contribution that only
  counts from the 99th percentile, and a retuned abnormal-timing threshold.
  The test split now shows 0.87 % (synthetic).
- Rule scores reached 100 regardless of rule severity; they are now bounded by
  severity.
- Activity-surge fired on new or rarely active assets. A minimum-baseline
  parameter and a neutral feature value for cold-start assets fix this
  (feature schema v2).
- Replaying an event under the `sync` queue cleared the tenant context and
  returned `500`.
- Evidence in the drawer was sorted across detectors with different score
  units; it is now grouped by detector.

### Added — Phase 2 (KRATOS EYE: ingestion, assets, monitoring)

- HMAC-signed ingestion API (`/api/v1/ingest/events`, `/batch`) with strict
  schema validation, normalisation, 256 KiB limit, per-credential rate limit.
- Deduplication by `Idempotency-Key` (conflict on reuse) and content fingerprint.
- Transactional outbox, relay worker (`kratos:outbox:relay`), idempotent
  processing job with exponential backoff, dead-lettering and replay.
- Ingestion keys (encrypted secrets, shown once, revocable), asset inventory,
  event explorer, overview statistics, retention job.
- Dashboard: live overview charts (validated colour palette, table views),
  Security Events explorer with detail drawer, Infrastructure, Ingestion Keys.
- `tools/kratos-send`: dependency-free Python client and synthetic traffic
  simulator (RFC 5737 addresses only).
- Labelled demonstration mode with 24 h of synthetic telemetry.
- ADR-0006 (ingestion credentials).

### Fixed — found during Phase 2 verification

- Ingestion returned `500` while Redis was down (rate limiter); limiters now
  fail open (ingestion) or closed with `503` (everything else).
- Per-credential rate limit fell back to per-IP because Laravel ran the
  throttle before the HMAC middleware.
- Crash-recovery check compared a cast enum to a string, so stuck claims
  were never released.
- Outbox relay used a non-existent query-builder method; `--once` runs now
  exit non-zero on failure instead of hiding it.

### Added — Phase 1 (Foundation)

- Architecture blueprint, five ADRs, and the full multi-phase database design.
- Docker Compose environment: PostgreSQL 17, Redis 7.4, Mailpit, php-fpm
  (PHP 8.4), queue worker, scheduler, Vite dev server, Nginx edge proxy.
- Least-privilege PostgreSQL roles: `kratos_owner` for migrations and
  `kratos_app` for runtime, with an append-only audit table.
- KRATOS CORE (Laravel 13):
  - Registration (user + first organisation), login, logout, signed email
    verification, password reset and change, session listing and revocation,
    personal access tokens with abilities and expiry.
  - RBAC with four roles and a code-defined permission catalogue synchronised
    to SQL.
  - Tenant isolation through route membership, a fail-closed query scope and
    policies.
  - Organisation settings, member management with a last-administrator
    invariant, and audit log API with filtering and pagination.
  - Consistent JSON envelopes, request IDs, structured JSON logging, security
    headers, rate limiting, and a health endpoint for Postgres and Redis.
  - OpenAPI 3.1 specification, with a contract test that keeps it in sync
    with the routes.
- KRATOS COMMAND (React 19 + TypeScript + Tailwind 4): authentication flows,
  security overview with live data only, organisation settings, members,
  audit log explorer, account security, and permission-aware navigation.
- Tests: PHPUnit against real PostgreSQL and Redis, Vitest, Playwright end to end.
- GitHub Actions CI: API tests, Pint, Composer and npm audits, lint,
  typecheck, frontend tests and build, end-to-end tests, and a production
  image build.

### Security

- API responses are never cached (`Cache-Control: no-store`).
- CORS is denied by default; the SPA is same-origin.

### Fixed (during Phase 1 verification)

- Members list could show stale or truncated data because of a query-cache
  key collision with the overview.
- Containerised Vite dev server did not pick up file changes on Docker Desktop.

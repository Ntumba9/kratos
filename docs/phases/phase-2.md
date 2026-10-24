# Phase 2 — Event ingestion, assets, event storage, monitoring dashboard

**Scope:** KRATOS EYE. Authenticated, validated, idempotent ingestion of
security events into PostgreSQL; reliable asynchronous processing through
Redis; asset inventory; and the dashboard views built on that data.

**Status:** Complete. All acceptance criteria are met; see the verification
record below.

## Deliverables

| # | Deliverable |
| --- | --- |
| D1 | Migrations: `api_credentials`, `monitored_assets`, `security_events`, `event_outbox` |
| D2 | Ingestion credentials: create / list / revoke, secret shown once, encrypted at rest, HMAC-signed requests (ADR-0006) |
| D3 | `POST /api/v1/ingest/events` (single) and `/ingest/events/batch` (≤ 100): strict schema validation, normalisation, size limits, per-credential rate limit |
| D4 | Deduplication: `Idempotency-Key` per organisation, payload fingerprint per organisation, conflict detection on key reuse |
| D5 | Transactional outbox + relay worker → Redis queue; idempotent `ProcessSecurityEvent` job with retries, exponential backoff, dead-letter state, and replay |
| D6 | Asset inventory API (CRUD, retire) with automatic `last_seen_at` |
| D7 | Event explorer API: filtering (severity, type, source, status, asset, time range, text), sorting, pagination, detail |
| D8 | Organisation overview statistics API (throughput per minute, 24 h timeline, severity / status distribution, top event types) |
| D9 | Retention job honouring `organisations.event_retention_days` |
| D10 | Dashboard: live overview charts, Security Events explorer, Infrastructure (assets), API credentials admin |
| D11 | `tools/kratos-send`: dependency-free Python client that signs and sends events, and a synthetic traffic simulator |
| D12 | Tests: PHPUnit, Vitest, Playwright; OpenAPI updated |

## Acceptance criteria

| ID | Criterion |
| --- | --- |
| AC1 | Unsigned, wrongly signed, stale-timestamp, revoked, expired or unknown-key requests are rejected with an identical `401` |
| AC2 | Malformed payloads (unknown fields, bad severity, bad timestamp, oversized metadata, wrong types) return `422` with field errors; bodies over the limit return `413` |
| AC3 | An accepted event is committed together with its outbox record **before** `202` is returned |
| AC4 | Re-sending with the same `Idempotency-Key` returns the original event (`200`, `duplicate: true`); reusing a key with a different payload returns `409`; identical payloads without a key are deduplicated |
| AC5 | If Redis is unavailable at ingestion time, the event is still accepted and is later published by the relay |
| AC6 | The processing job is idempotent (redelivery does not reprocess), retries with exponential backoff, and on exhaustion marks the event `dead_lettered` — never silently dropped; dead-lettered events can be replayed |
| AC7 | Ingestion is rate limited per credential |
| AC8 | Events, assets and credentials are tenant isolated (a credential can only write into its own organisation; members of other organisations get `404`) |
| AC9 | Dashboard statistics come exclusively from these endpoints; no hard-coded figures |
| AC10 | Events older than the organisation's retention period are purged by the scheduler |
| AC11 | Every new route is in `openapi.yaml` (contract test) |

## Delivery guarantees (as implemented)

- **At-least-once** from `202 Accepted` to processing; **idempotent** effects.
- Exactly-once processing is **not** claimed.
- See ARCHITECTURE §5 and ADR-0005.

## Acceptance evidence

| ID | Evidence |
| --- | --- |
| AC1 | `IngestionAuthenticationTest` (7 rejection variants + tampered body + revoked/expired, all identical `401`); e2e forged signature |
| AC2 | `IngestionValidationTest` (13 malformed-payload cases, 413, 400, 415, batch limits) |
| AC3 | `ProcessingPipelineTest::test_an_accepted_event_is_committed_with_its_outbox_record_and_queued` |
| AC4 | `IngestionDeduplicationTest` (key replay, key conflict `409`, fingerprint with reordered keys and different offsets, per-tenant scope); e2e duplicate `200` |
| AC5 | `ProcessingPipelineTest` (Redis down → accepted → relay publishes), `RateLimiterOutageTest`, **live drill** below |
| AC6 | `ProcessingPipelineTest` (idempotent redelivery, stale-claim recovery, failure → `failed` → `dead_lettered`, replay, backoff policy) |
| AC7 | `IngestionAuthenticationTest::test_ingestion_uses_its_own_per_credential_rate_limit`, `RateLimiterOutageTest` |
| AC8 | `EventExplorerTest`, `AssetApiTest`, `ApiCredentialApiTest`, `IngestionValidationTest::test_an_asset_of_another_organisation_cannot_be_referenced` |
| AC9 | `OverviewStatisticsTest` (exact counts, tenant-only, zeros when empty); e2e overview tiles; `charts.test.tsx` |
| AC10 | `OverviewStatisticsTest::test_retention_purges_only_events_past_each_organisations_period` |
| AC11 | `OpenApiContractTest` (13 new operations) |

## Verification record

Executed 2026-10-23/24 on the same machine as Phase 1 (Windows 11, i3-1215U,
7.7 GB RAM, Docker Desktop 29.8.0, repository in a OneDrive folder). Actual
results only.

| Suite | Result |
| --- | --- |
| PHPUnit (real PostgreSQL + Redis) | **168 passed, 1 224 assertions** |
| Pint | pass (152 files) |
| Vitest | **6 files, 20 tests passed** |
| ESLint / TypeScript / production build | clean / clean / built |
| Python client (`unittest`) | **7 passed** (incl. signature vector shared with PHP, computed independently with Python `hmac`) |
| Playwright (full stack) | **6 passed** (7.4 min, `KRATOS_E2E_EXPECT_TIMEOUT=60000`) |
| Composer / npm audit | no advisories / 0 vulnerabilities |

### Live pipeline runs (through Nginx → php-fpm → PostgreSQL → Redis → worker)

| Run | Observation |
| --- | --- |
| 60 synthetic events (`kratos-send simulate`) | 60 × `202`; all `processed`; 0 unpublished outbox rows; mean receipt→processed **717 ms** |
| 80 synthetic events with asset | 80 × `202`; all processed; asset linked and `last_seen_at` updated |

Request latency in these runs (~2.6 s per request) reflects the Docker
Desktop bind-mount overhead on this machine, not the application; no
performance claims are made until Phase 6 benchmarks.

### Redis outage drill

1. `docker compose stop redis`, then ingest 3 events.
   - **Before the fix:** 3 × `500` (the rate limiter needs Redis) — defect found.
   - **After the fix:** 3 × `202`; events stored as `received`, outbox rows
     unpublished with `RedisException: Connection refused`, relay retrying with
     backoff. `forgot-password` (credential endpoint) returned `503` (fails closed).
2. `docker compose start redis` at 23:11:41 → every event `processed` and every
   outbox row published by 23:12:12. **No events lost.**

## Defects found and fixed during verification

1. **Redis outage → ingestion `500`.** Rate limiting had no defined outage
   behaviour. `ResilientThrottleRequests` now fails open for ingestion and
   closed (`503`) for everything else. Regression: `RateLimiterOutageTest`.
2. **Per-credential rate limit silently per-IP.** Laravel's middleware
   priority ran `ThrottleRequests` before the HMAC middleware. The middleware
   now implements `AuthenticatesRequests`. Regression: second credential keeps
   its own budget.
3. **Stuck-claim recovery never triggered.** `Builder::value()` returned the
   enum cast, compared against a string. Fixed with `toBase()`. Regression:
   stale-claim test.
4. **Outbox relay errors hidden.** The relay called a non-existent
   `skipLocked()` method and the loop swallowed the exception. Fixed
   (`FOR UPDATE SKIP LOCKED`); `--once` now exits non-zero on failure.
5. **Override broke named limiters.** Forwarding explicit arguments made
   Laravel treat `ingest` as a numeric limit; fixed by forwarding
   `func_get_args()`.
6. Dashboard: alphabetical (not ordinal) chart legends and series-coloured
   legend text; duplicate accessible names on filter controls.
7. Initial severity palette failed four colour-validator checks (CVD and
   normal-vision separation, lightness band, chroma); replaced with a validated
   one-hue ordinal ramp.

## Known limitations

- Events are processed but not yet scored (KRATOS MIND, Phase 3).
- Event list totals use `COUNT(*)`; very large tenants will need keyset
  pagination or estimated counts (to be decided by Phase 6 benchmarks).
- Severity timeline buckets by receipt time (`received_at`), not
  `occurred_at`; late-arriving events show when they arrived.
- HMAC with a shared secret (ADR-0006); asymmetric signatures are a Phase 6
  candidate.

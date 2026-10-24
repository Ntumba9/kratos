# KRATOS Architecture

> Autonomous Threat Intelligence & Cyber Defense Platform — *Built to Detect. Engineered to Defend.*

This document is the architecture blueprint for KRATOS. It describes service
boundaries, data flow, the database design, the delivery guarantees of the
processing pipeline, and the security model. Decisions with lasting
consequences are recorded as ADRs in [`docs/adr/`](docs/adr/).

Status markers used below:

| Marker | Meaning |
| --- | --- |
| **Implemented** | Code exists, is tested, and is exercised by CI. |
| **Planned (Phase N)** | Designed here; not yet built. |

---

## 1. System context

```
                         ┌────────────────────────────────────────────┐
  Analyst browser ──────►│  Nginx (edge)  :8080                        │
                         │   /            → KRATOS COMMAND (React SPA) │
                         │   /api, /sanctum → KRATOS CORE (php-fpm)    │
                         └───────────────┬────────────────────────────┘
                                         │
             ┌───────────────────────────┼─────────────────────────────┐
             │                           ▼                             │
             │   KRATOS CORE (Laravel)  ── modules: Identity, Tenancy, │
             │                              Audit, EYE, GUARD          │
             │        │            │                   │               │
             │        ▼            ▼                   ▼               │
             │   PostgreSQL     Redis  ◄──── queue workers (Laravel)    │
             │   (system of     (queues,            │                  │
             │    record)        cache, limits)     │ service-to-      │
             │                                      ▼ service auth     │
             │                          KRATOS MIND (FastAPI, internal │
             │                          network only)                  │
             └─────────────────────────────────────────────────────────┘
                                         ▲
  KRATOS AGENT (operator hosts) ─────────┘  HTTPS, per-agent credential
  Authorised applications ──── event ingestion API (per-client credential)
```

## 2. Components and responsibilities

KRATOS is a modular, service-oriented system. There are **three deployable
runtimes** (Laravel, FastAPI, React static bundle) plus a stand-alone agent.
EYE and GUARD are bounded *modules* inside the Laravel runtime rather than
separate services: they share the same transactional database, and splitting
them into network services would add latency and distributed-transaction
complexity without a scaling benefit at this stage (see
[ADR-0002](docs/adr/0002-modular-monolith-core.md)). Module logic lives in
`App\Domain\<Module>` and cross-module calls go through explicit service
classes (e.g. `OrganisationManager`, `AuditLogger`). Eloquent models stay in
`App\Models` per Laravel convention.

| Component | Runtime | Responsibilities | Status |
| --- | --- | --- | --- |
| **KRATOS CORE** | Laravel (PHP 8.4) | Authentication, users, organisations, RBAC, versioned REST API, audit logging, configuration, transactions | **Implemented** (Phase 1) |
| **KRATOS EYE** | Laravel module | Ingestion API, schema validation, normalisation, deduplication, idempotency, asset tracking, telemetry storage, dispatch to analysis | **Implemented** (Phase 2) |
| **KRATOS MIND** | FastAPI (Python 3.12+) | Deterministic detection rules, feature extraction, Isolation Forest / statistical anomaly detection, risk scoring, explanations, model versioning; investigation summaries in Phase 4 | **Implemented** (Phase 3); stateless, on the internal network only |
| **KRATOS GUARD** | Laravel module | Incident creation, correlation, severity, status workflow, alerts, recommended responses gated by human approval, analyst decisions | Planned (Phase 4) |
| **KRATOS COMMAND** | React + TypeScript + Vite | SOC dashboard: overview, events, incidents, intelligence, infrastructure, administration | Overview, events, infrastructure, admin, threat intelligence **Implemented** (Phases 1–3); incidents Phase 4 |
| **KRATOS AGENT** | Python | Opt-in host telemetry (health, resource metrics, approved logs), registration, heartbeat, signed transmission | Planned (Phase 5) |

### Explicit non-goals

- No offensive capability: no scanning of third-party systems, no exploitation.
- No autonomous disruptive response. GUARD *recommends*; any action that
  could disrupt a system requires an explicit, audited human approval.
- The agent has no stealth, persistence-evasion, credential collection or
  intrusive monitoring features. It collects only what its configuration file
  enumerates, on hosts the operator controls.

## 3. Repository layout

```
KRATOS/
├── core/                 KRATOS CORE — Laravel application (also EYE, GUARD modules)
│   ├── app/Domain/       Module logic: Identity, Tenancy, Audit (Eye, Guard from Phase 2/4)
│   ├── app/Models/       Eloquent models (Laravel convention; tenant models use BelongsToOrganisation)
│   ├── app/Http/         Controllers, middleware, requests, resources (API layer)
│   ├── database/         Migrations, factories, seeders
│   └── tests/            PHPUnit feature + unit tests
├── command/              KRATOS COMMAND — React SPA
├── mind/                 KRATOS MIND — FastAPI service, training CLI, pytest suite
├── contracts/mind/       CORE ⇄ MIND contract fixtures shared by PHPUnit and pytest
├── infra/
│   ├── docker/           Dockerfiles
│   ├── nginx/            Edge proxy configuration
│   └── postgres/         Database bootstrap (roles, least-privilege grants)
├── docs/
│   ├── adr/              Architecture decision records
│   ├── api/openapi.yaml  OpenAPI 3.1 contract (route coverage is tested)
│   ├── database.md       Full schema design (all phases)
│   └── phases/           Per-phase deliverables, acceptance criteria, verification
├── e2e/                  Playwright end-to-end tests
├── .github/workflows/    CI
└── docker-compose.yml    Local development environment
```

`agent/` is added in Phase 5.

## 4. Request flow and authentication model

KRATOS has four distinct classes of caller and uses a different mechanism for
each ([ADR-0003](docs/adr/0003-authentication-model.md)):

| Caller | Mechanism | Why |
| --- | --- | --- |
| Browser (COMMAND) | Sanctum **stateful session cookie** + CSRF (`XSRF-TOKEN`), same origin via Nginx | No token in JavaScript-reachable storage; session revocation is server-side |
| Scripts / API users | Sanctum **personal access tokens** (`Authorization: Bearer`), hashed at rest, scoped by abilities, expiring | Standard programmatic access |
| Ingestion clients & agents | Per-organisation **API credentials**: public `key_id` + secret, secret stored **encrypted** (needed to verify HMAC); requests HMAC-SHA256 signed with a ±300 s timestamp window ([ADR-0006](docs/adr/0006-ingestion-credentials.md)) | Bound to a tenant, revocable, no user identity required *(implemented Phase 2; agents Phase 5)* |
| CORE ⇄ MIND | Shared-secret **HMAC-signed requests** (method, path, timestamp, body hash; ±300 s) on the `internal: true` Docker network `intelligence`; MIND publishes no port and is not routed by Nginx | Service identity and integrity. No nonce: a request replayed inside the window only recomputes the same pure analysis (MIND is stateless) |

### Tenancy

Every tenant-owned resource is addressed under
`/api/v1/organisations/{organisation}/…`. Isolation is enforced in **three
independent layers** so that a mistake in one does not leak data:

1. **Route middleware** (`organisation.member`) resolves the caller's
   membership in `{organisation}`. Non-members receive `404` (not `403`) so
   organisation identifiers cannot be enumerated.
2. **Query layer**: tenant models use the `BelongsToOrganisation` trait, which
   applies a global scope bound to the resolved `TenantContext`. A query for a
   tenant model with no tenant context **fails closed** (throws) instead of
   returning every tenant's rows.
3. **Policy layer**: Laravel policies check role permissions *and* that the
   target's `organisation_id` equals the current tenant.

Feature tests in `core/tests/Feature/Tenancy` prove that a member of
organisation A cannot read or mutate organisation B's resources through any
of these paths.

### RBAC

Roles and permissions are data (tables `roles`, `permissions`,
`permission_role`), synchronised from `config/kratos.php` by a seeder so the
catalogue is versioned in code and queryable in SQL.

| Role | Scope | Summary |
| --- | --- | --- |
| Platform Administrator | platform | Manage all organisations and platform settings |
| Organisation Administrator | organisation | Manage members, roles, credentials, settings within one organisation |
| Security Analyst | organisation | Investigate events and incidents, record decisions |
| Read-Only Viewer | organisation | View-only |

Authorisation is always evaluated server-side; the SPA only uses permissions
returned by `/api/v1/auth/me` to hide controls it knows will be rejected.

## 5. Event processing pipeline

Implemented through detection (Phase 3); correlation (GUARD) plugs in after
analysis in Phase 4.

```
 POST /api/v1/ingest/events  (or /batch, ≤ 100)
   │  body ≤ 256 KiB → HMAC signature + timestamp (ADR-0006) → tenant = credential's organisation
   │  per-credential rate limit (600/min) → strict schema validation + normalisation
   │  dedupe: Idempotency-Key (409 if reused for different content) / content fingerprint
   ▼
 BEGIN; INSERT security_events (received); INSERT event_outbox; COMMIT   ← before acknowledging
   │  202 Accepted {id}   (200 {duplicate: true} for a repeat)
   ▼
 inline publish → Redis queue "events" ── fails? row stays unpublished ──► outbox relay (retries, backoff ≤ 300 s)
   ▼
 worker ProcessSecurityEvent: atomic claim (idempotent) → enrich (asset link, last_seen)
   │  → ContextBuilder (one SQL query: per-IP/user/asset windows, type rarity, 7-day baselines)
   │  → MIND POST /internal/v1/analyse (signed) → risk, verdict, category, evidence
   │  → one transaction: update event + replace its detection_results → processed
   │  MIND unreachable / 5xx → status failed, "waiting to retry", release with backoff 10…300 s;
   │    not counted towards dead-lettering (retryUntil 6 h); 4xx (contract error) → normal failure path
   │  failure → status failed, retry with backoff 10/30/90/270 s (5 attempts)
   │  exhausted → dead_lettered (kept, visible, replayable via API/UI)
   ▼
 Phase 4: detection_results → GUARD correlation → incidents/alerts
```

**MIND outage behaviour** (ADR-0007; verified in a live drill, Phase 3
record): ingestion keeps accepting events; they wait in `failed` with a
retry message and are analysed when MIND returns. None are dead-lettered by
the outage. `/api/v1/health` reports `degraded` with `mind: down`.

**Redis outage behaviour** (verified in a live drill, Phase 2 record): the
rate limiter for ingestion fails *open* so telemetry is still accepted and
stored, while the credential endpoints' limiter fails *closed* (`503`).
The relay publishes the backlog when Redis returns; nothing is lost.

### Delivery guarantees

- **At-least-once** from ingestion acknowledgement to processing. The event and
  its outbox record are committed atomically before `202` is returned, so an
  acknowledged event cannot be lost by a crashed worker or an unavailable
  Redis; the relay (`kratos:outbox:relay`, `FOR UPDATE SKIP LOCKED`) re-publishes
  unsent outbox rows.
- **Crash recovery**: a worker claim that is older than 5 minutes is treated
  as abandoned and can be re-claimed; a redelivered job that finds a fresh claim
  releases itself and checks back later.
- **Idempotent consumers**: workers may see a message more than once.
  Writes are keyed by natural unique constraints (e.g.
  `detection_results(security_event_id, detection_rule_id, model_version_id)`)
  and use upserts, so redelivery does not duplicate results or incidents.
- **Exactly-once is not claimed.** Redis queues offer no transactional
  acknowledgement across Redis and PostgreSQL; the guarantee is
  *at-least-once delivery + idempotent effects*.
- **No silent loss**: exhausted jobs move to a dead-letter state recorded in
  PostgreSQL and surfaced through the API.

## 6. Database design

PostgreSQL is the system of record. The full schema (all phases), with keys,
indexes, constraints, and retention policy, is in
[`docs/database.md`](docs/database.md). Highlights:

- UUID (v7, time-ordered) primary keys for every externally visible entity —
  no enumerable integer IDs in URLs, index locality preserved.
- Every tenant table carries `organisation_id` with a foreign key and a
  leading composite index (`organisation_id, …`).
- `audit_logs` is append-only: the application database role is granted
  `INSERT, SELECT` only on that table.
- Secrets are never stored in plaintext: passwords use Argon2id; API tokens
  and credential secrets are stored as SHA-256/HMAC digests.

### Least-privilege database roles

| Role | Used by | Privileges |
| --- | --- | --- |
| `kratos_owner` | Migrations only | Owns schema objects |
| `kratos_app` | Running application and workers | `SELECT/INSERT/UPDATE/DELETE` on tables, except `audit_logs` (`SELECT/INSERT`) |

Created by `infra/postgres/init/01-roles.sh`.

## 7. Cross-cutting concerns

| Concern | Approach |
| --- | --- |
| Response format | `{"data": …, "meta": …}` on success; `{"error": {"code", "message", "details"}, "request_id"}` on failure |
| Correlation | `X-Request-Id` accepted or generated; echoed in responses and every log line |
| Logging | JSON lines to stderr (Monolog `JsonFormatter`), credentials redacted |
| Security headers | API: CSP `default-src 'none'`, `nosniff`, `DENY` framing, `no-referrer`; SPA via Nginx with a strict CSP |
| Rate limiting | Redis-backed named limiters (`auth`, `api`, `ingest` per credential); explicit fail-open (`ingest`) / fail-closed (others, `503`) when Redis is unavailable |
| Metrics | Prometheus `/metrics` on an internal port *(Phase 5)* |
| Tracing | OpenTelemetry spans across CORE → MIND *(Phase 5)* |

## 8. Technology choices

| Technology | Why |
| --- | --- |
| Laravel + Sanctum | Mature auth, queues, policies, migrations; Sanctum supports both first-party SPA cookies and tokens |
| PostgreSQL | Transactions, `jsonb` for event metadata with GIN indexes, partial and composite indexes, role-based privileges |
| Redis | Queue transport, rate limiter and cache state with low latency |
| FastAPI + scikit-learn | Typed (Pydantic) internal API and a well-understood ML toolkit; no paid AI API required |
| React + TypeScript + Vite | Typed SPA with fast tooling; Tailwind for a consistent design system; Recharts for charts; Lucide for icons |
| TanStack Query | Server-state caching, retries and invalidation without hand-written fetch state machines |
| Nginx | Single origin for SPA and API (required for cookie auth without cross-site cookies), security headers |
| Docker Compose | One-command reproducible environment |

## 9. Phase plan

| Phase | Scope | Doc |
| --- | --- | --- |
| 1 | Repository, dev environment, Laravel API, database, authentication, RBAC, tenancy, React shell | [docs/phases/phase-1.md](docs/phases/phase-1.md) |
| 2 | Event ingestion, assets, event storage, monitoring dashboard | [docs/phases/phase-2.md](docs/phases/phase-2.md) |
| 3 | MIND: detection rules, anomaly detection, risk scoring | [docs/phases/phase-3.md](docs/phases/phase-3.md) |
| 4 | GUARD: correlation, investigations, reports | — |
| 5 | Agent, processing reliability, observability | — |
| 6 | Hardening, benchmarks, CI/CD, deployment preparation | — |

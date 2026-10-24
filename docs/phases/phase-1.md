# Phase 1 â€” Foundation

**Scope:** repository architecture, development environment, Laravel API,
database, authentication and authorisation, tenant isolation, React
application shell.

**Status:** Complete. All acceptance criteria are met; see the verification
record below.

## Deliverables

| # | Deliverable | Location |
| --- | --- | --- |
| D1 | Architecture blueprint, service boundaries, ADRs | `ARCHITECTURE.md`, `docs/adr/` |
| D2 | Full database design (all phases) and Phase 1 migrations | `docs/database.md`, `core/database/migrations/` |
| D3 | Reproducible Docker Compose environment (Postgres, Redis, Mailpit, php-fpm, worker, scheduler, Vite, Nginx) | `docker-compose.yml`, `infra/` |
| D4 | Least-privilege database roles (owner vs. application) | `infra/postgres/init/01-roles.sh` |
| D5 | Authentication: registration, login, logout, email verification, password reset, password change, session management, personal access tokens | `core/app/Http/Controllers/Api/V1/Auth/` |
| D6 | RBAC: four roles, permission catalogue in code, synced to SQL | `core/config/kratos.php`, `RbacSeeder` |
| D7 | Tenant isolation in three layers (route, query scope, policy) | `core/app/Domain/Tenancy/`, `ResolveOrganisation` |
| D8 | Append-only audit logging of security-relevant actions | `core/app/Domain/Audit/` |
| D9 | Versioned REST API with consistent envelopes, pagination, sorting, filtering, rate limits, security headers, request IDs | `core/routes/api.php`, `core/app/Http/` |
| D10 | OpenAPI 3.1 contract, enforced by a test | `docs/api/openapi.yaml`, `OpenApiContractTest` |
| D11 | KRATOS COMMAND shell: auth flows, overview, organisation settings, members, audit log, account security | `command/src/` |
| D12 | Automated tests: PHPUnit (API), Vitest (SPA), Playwright (end to end) | `core/tests`, `command/src/**/*.test.tsx`, `e2e/` |
| D13 | CI pipeline | `.github/workflows/ci.yml` |

## Acceptance criteria

| ID | Criterion | Evidence |
| --- | --- | --- |
| AC1 | `docker compose up -d` starts the stack from a clean checkout with only `.env` passwords set; migrations and RBAC seeding run automatically | `core-init` service; verified locally |
| AC2 | The running app connects as `kratos_app`, which cannot run DDL or alter/delete audit records | `DatabasePrivilegesTest`; the first test run failed with `must be owner of table` when it was accidentally pointed at `kratos_app`, which shows the restriction works |
| AC3 | A user can register, receive a verification email, verify, sign in, sign out and reset a password | `Auth/*Test`, `e2e/tests/auth.spec.ts` |
| AC4 | Unverified users cannot access tenant resources | `EmailVerificationTest::test_unverified_users_cannot_reach_tenant_resources`, e2e |
| AC5 | Login is rate limited and failed attempts are audited without the password | `LoginTest` |
| AC6 | Personal access tokens are stored hashed, expire, are ability-restricted and revocable | `AccessTokenTest` |
| AC7 | Users can list and revoke their own sessions only; raw session IDs are never exposed | `SessionManagementTest` |
| AC8 | Members of organisation A cannot read or modify organisation B through any route, ID substitution or query | `TenantIsolationTest`, `TenantScopeTest`, e2e `organisation.spec.ts` |
| AC9 | Role permissions are enforced server-side for every role | `MembershipTest::roleMatrix`, `OrganisationTest`, `AuditLogTest` |
| AC10 | An organisation cannot lose its last administrator | `MembershipTest` |
| AC11 | All API errors use the documented envelope and never leak internals | `HealthAndResilienceTest`, `assertErrorEnvelope` throughout |
| AC12 | Every API route is documented in OpenAPI and vice versa | `OpenApiContractTest` |
| AC13 | The SPA shows no hard-coded statistics; modules for later phases are clearly labelled | `OverviewPage`, `PlannedModulePage`, `AppShell.test.tsx` |
| AC14 | Redis outage is reported as degraded health (503) without leaking details | `HealthAndResilienceTest` |

## Verification record

Executed on 2026-10-23 on the development machine described below. These
are the actual results of the commands listed; nothing was extrapolated.

**Environment:** Windows 11 Home, 12th Gen Intel Core i3-1215U, 7.7 GB RAM;
Docker Desktop 29.8.0 (WSL2, 8 vCPU / ~3.7 GB allocated); PHP 8.4.27 (container),
Laravel 13.38.0, PostgreSQL 17, Redis 7.4, Node 26.3 (host) / 22 (container).

| Suite | Command | Result |
| --- | --- | --- |
| PHPUnit (API, real PostgreSQL + Redis) | `docker compose exec core php artisan test` | **89 passed, 514 assertions** (77 s) |
| Pint (code style) | `vendor/bin/pint --test` | pass (102 files) |
| Composer / npm audit | `composer audit --no-dev`, `npm audit --omit=dev` | no advisories / 0 vulnerabilities |
| Vitest (SPA) | `npm test` in `command/` | **4 files, 14 tests passed** |
| TypeScript | `npm run typecheck` | no errors |
| ESLint | `npm run lint` | no problems |
| Production build | `npm run build` | succeeded (JS 360.8 kB / 111.6 kB gzip) |
| Playwright (end to end, full stack) | `KRATOS_E2E_EXPECT_TIMEOUT=60000 npx playwright test` in `e2e/` | **5 passed** (9.0 min; slow because of the bind-mount latency noted below) |
| Manual smoke test | Register, verify via Mailpit link, dashboard, account security in a browser | worked; verification email delivered by the Redis queue worker |

Defects found and fixed during verification:

1. API responses carried `Cache-Control: no-cache` instead of `no-store`:
   Symfony always sets a default header, so the "set if absent" logic never
   applied. Fixed in `SecurityHeaders`; covered by `SecurityHeadersTest`.
2. Laravel's default CORS configuration returned `Access-Control-Allow-Origin: *`.
   It was not exploitable for credentialed requests, but it was unnecessary.
   Replaced with an explicit allow-list (empty by default); covered by a test.
3. PHPUnit `<env>` values did not override container environment variables,
   so tests initially targeted the main database as `kratos_app`. Fixed by
   forcing `<server>` values as well.
4. A React effect copied server data into form state (lint
   `react-hooks/set-state-in-effect`); refactored into a keyed form component.
5. **Cache-key collision (found by Playwright):** the Overview (`per_page=1`)
   and Members page (`per_page=25`) shared one TanStack Query key, so the
   member list could show a single row and did not refresh after adding a
   member. Page size is now part of the key; regression test in
   `queries.test.ts`.
6. End-to-end API assertions were sent without `Referer`, so Sanctum treated
   them as non-browser requests. The post-logout "401" check therefore passed
   for the wrong reason. Test calls now identify the SPA origin (`apiGet`).
7. The containerised Vite server missed file changes (no file-system events
   across Docker Desktop bind mounts) and kept serving stale code. Polling is
   now enabled in Compose.

## Known limitations (carried forward)

- Members are added by email only if they already have a verified account;
  email invitations are not implemented.
- No multi-factor authentication yet (planned hardening item for Phase 6).
- On Windows with the repository inside a synced folder (e.g. OneDrive),
  bind-mounted PHP is slow: the health endpoint measured 1.3–3.5 s per
  request with containers otherwise idle. Cloning into
  the WSL2 filesystem avoids this. See README troubleshooting.
- PostgreSQL row-level security is not enabled (ADR-0004); isolation relies on
  the three application layers and their tests.

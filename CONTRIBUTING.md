# Contributing to KRATOS

## Ground rules

- **Security first.** Never weaken an authorisation check, tenant scope, or
  security header without an ADR explaining why.
- **No fake functionality.** Features must be backed by working endpoints and
  tests. Use explicit "planned" states rather than sample data in production
  views. Never publish unmeasured performance figures or untested claims.
- **Synthetic data only** in fixtures, seeders and demonstrations.

## Workflow

1. Create a branch from `main`: `feat/…`, `fix/…`, `docs/…`.
2. Make focused changes with tests.
3. Run the checks below locally.
4. Open a pull request describing the change, its risk, and how it was verified.

## Required checks

```bash
# API
docker compose run --rm core-init php artisan test
docker compose run --rm core-init vendor/bin/pint --test

# SPA
cd command && npm run lint && npm run typecheck && npm test && npm run build

# End to end (stack running)
cd e2e && npx playwright test
```

CI runs all of the above plus dependency audits and a production image build.

## Conventions

**Backend**
- Controllers stay thin; domain logic lives in `app/Domain/<Module>`.
- New tenant-owned models **must** use the `BelongsToOrganisation` trait, be
  reached through `/organisations/{organisation}/…` routes, and get isolation
  tests.
- New API routes must be added to `docs/api/openapi.yaml`;
  `OpenApiContractTest` fails otherwise.
- Return responses through `App\Http\Responses\ApiResponse` so envelopes stay consistent.
- Security-relevant actions are recorded with `AuditLogger::record()`.
- Migrations run as `pgsql_owner`. If a table must be append-only or otherwise
  restricted for the app role, add the `REVOKE` in its migration.

**Frontend**
- All server data comes from the API through TanStack Query; never hard-code
  statistics.
- Permission checks in the UI are hints; the API is the authority.
- Use the shared UI primitives in `src/components/ui`.

**Architecture decisions**
- Add an ADR in `docs/adr/` for changes to service boundaries, data
  ownership, authentication, or delivery guarantees.

## Commit messages

Use the imperative mood with a short subject line (≤ 72 characters), for
example `Add idempotency keys to event ingestion`, and explain the *why* in
the body when it isn't obvious.

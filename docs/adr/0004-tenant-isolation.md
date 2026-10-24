# ADR-0004: Tenant isolation in three layers

- Status: Accepted
- Date: 2026-10-23

## Context
KRATOS is multi-tenant: one database holds many organisations' security
telemetry. A single missed `where organisation_id = ?` would be a serious
data leak. Row-level security (RLS) in PostgreSQL was considered.

## Decision
Shared schema, `organisation_id` on every tenant table, enforced by:
1. **Routing**: tenant resources live under
   `/api/v1/organisations/{organisation}/…`; middleware resolves membership
   and returns `404` for non-members (no existence oracle).
2. **Query scope**: a `BelongsToOrganisation` trait adds a global scope from
   the request-bound `TenantContext`; querying a tenant model with no context
   throws `MissingTenantContext` (fail closed). Background jobs set the
   context explicitly from the payload.
3. **Policies**: authorise both the permission and that
   `$model->organisation_id === $tenant->id`.

PostgreSQL RLS is deferred: it is a strong fourth layer, but it requires
setting a session variable per transaction through PgBouncer-compatible
means, and complicates migrations and cross-tenant platform administration.
It is recorded as a Phase 6 hardening candidate.

## Consequences
- Isolation is tested at each layer (`tests/Feature/Tenancy`).
- Platform administrators access organisations through explicit
  platform-scoped endpoints, never by bypassing the tenant scope implicitly.

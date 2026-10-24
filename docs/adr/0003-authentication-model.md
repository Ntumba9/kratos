# ADR-0003: Authentication model

- Status: Accepted (clause 3, credential storage, superseded by ADR-0006)
- Date: 2026-10-23

## Context
KRATOS has browser users, programmatic API users, machine ingestion clients
(applications and agents), and an internal service (MIND). Each has a
different threat model.

## Decision
1. **Browser**: Laravel Sanctum stateful (session cookie) authentication. The
   SPA and API share one origin through Nginx, so cookies are `SameSite=Lax`,
   `HttpOnly`, and `Secure` outside local development. CSRF protection uses
   Laravel's `XSRF-TOKEN` double-submit cookie. No bearer token is ever placed
   in browser storage. Sessions are stored in PostgreSQL so users can list and
   revoke their active sessions.
2. **Programmatic users**: Sanctum personal access tokens with explicit
   abilities and a mandatory expiry (default 90 days, max 365). Only a
   SHA-256 digest is stored; the plaintext is shown once.
3. **Ingestion clients and agents** (Phase 2/5): organisation-bound API
   credentials (`key_id` + secret). The secret is stored as an HMAC digest.
   Requests carry `X-Kratos-Key`, `X-Kratos-Timestamp` and an HMAC-SHA256
   signature over method, path, timestamp and body hash; timestamps outside
   ±300 s are rejected to limit replay.
4. **CORE ⇄ MIND** (Phase 3): the same HMAC scheme with a service secret,
   over an internal Docker network; MIND has no published port.
5. Passwords are hashed with Argon2id. Login is rate-limited per
   (email, IP) and per IP; failures are audited without recording the
   attempted password.
6. Email verification is required before tenant resources can be accessed
   (`verified` middleware); unverified users can only manage their own
   account and resend verification.

## Consequences
- Same-origin deployment is required for the SPA. Cross-origin SPA hosting
  would need `SANCTUM_STATEFUL_DOMAINS` and CORS changes.
- Machine credentials are separate from user identities, so revoking a
  departing analyst never breaks ingestion and vice versa.

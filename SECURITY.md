# Security

KRATOS is security software, so its own security posture is a core
requirement, not an afterthought. This document describes the threat model,
the controls in place, and how to report vulnerabilities.

## Reporting a vulnerability

Please **do not** open a public issue for security problems. Report them
privately through the repository's GitHub Security Advisories ("Report a
vulnerability"). Include reproduction steps and the affected version or
commit. Reports are acknowledged as soon as practicable; there is no bug bounty.

## Scope and intended use

- KRATOS processes security telemetry **from systems its operator controls**.
  It contains no offensive tooling, no scanning of third-party systems, and no
  stealth or persistence features in the agent.
- Potentially disruptive defensive actions (Phase 4) are *recommendations*
  that require explicit, audited human approval.
- All demonstration data is synthetic.

## Threat model (summary)

| Asset | Threats | Primary controls |
| --- | --- | --- |
| Tenant security data | Cross-tenant access, IDOR | Route-level membership check (404 for non-members), fail-closed query scope, policies; tested per layer |
| User accounts | Credential stuffing, brute force, session theft | Argon2id, per-identifier and per-IP rate limits, HttpOnly/SameSite cookies, session regeneration, server-side session revocation |
| API tokens and secrets | Leakage, replay | Personal tokens stored only as digests; ingestion secrets encrypted at rest, never sent on the wire after issue (HMAC-SHA256 signatures, ±300 s window, idempotent replays); all shown once |
| Audit trail | Tampering | Append-only at model layer **and** database privilege layer (`kratos_app` has no `UPDATE`/`DELETE` on `audit_logs`) |
| Platform | Injection, XSS, CSRF, clickjacking | Validated input, parameterised queries, allow-listed sort/filter, React escaping, strict CSP, CSRF double-submit token, `X-Frame-Options: DENY` |

## Controls in place (Phase 1)

**Authentication and sessions**
- Passwords: Argon2id; minimum 12 characters with mixed case and digits;
  Have I Been Pwned k-anonymity check in production.
- Login throttled at 5/min per (email, IP) and 30/min per IP. Failures are
  audited with the attempted identifier, never the password. The response for
  an unknown account is identical to a wrong password.
- Password reset responses do not reveal whether an account exists. A reset
  ends every session; a password change ends all other sessions.
- Email verification is required before any tenant data is accessible.
- Sessions are stored server-side (PostgreSQL), encrypted, and revocable by
  the user. Session IDs are never returned by the API.

**Authorisation and tenancy**
- Every endpoint authorises server-side via Laravel Gates and policies built
  from the RBAC catalogue. There is no implicit super-user: platform
  administrators still need membership to read tenant data.
- Organisation resources are only reachable under
  `/organisations/{organisation}` after a membership check; route parameters
  are UUID-constrained.

**API hardening**
- Strict security headers on every API response (`default-src 'none'` CSP,
  `nosniff`, `DENY` framing, `no-referrer`, `no-store` caching, COOP/CORP).
- No CORS grants by default (the SPA is same-origin).
- Consistent error envelope; internal exception details are never returned
  unless `APP_DEBUG=true`.
- Request size limited at the edge (1 MB) and by PHP (`post_max_size`).
- Every request has an `X-Request-Id`, propagated into structured JSON logs and
  audit records.

**Data and infrastructure**
- Separate PostgreSQL roles: `kratos_owner` (migrations) and `kratos_app`
  (runtime DML only, no DDL, append-only audit log).
- Redis requires a password; all host ports bind to `127.0.0.1` only.
- No credentials in the repository: `.env` files are git-ignored, Compose
  refuses to start without passwords, and the demo seeder generates a random
  password per run.
- Dependency vulnerability scanning in CI (`composer audit`, `npm audit`).

## OWASP mapping (ASVS-oriented)

| OWASP area | Where addressed |
| --- | --- |
| A01 Broken Access Control | Tenancy layers, Gates/policies, role-matrix tests |
| A02 Cryptographic Failures | Argon2id, digest-only token storage, encrypted sessions, HTTPS + HSTS in production |
| A03 Injection | Eloquent parameter binding, allow-listed sort columns, escaped `LIKE` filters |
| A04 Insecure Design | ADRs, threat model, fail-closed tenant scope, last-admin invariant |
| A05 Security Misconfiguration | Hardened headers, no default CORS, `expose_php=Off`, least-privilege DB |
| A07 Identification & Authentication Failures | Rate limiting, uniform failure responses, session regeneration and revocation |
| A08 Software & Data Integrity Failures | Lockfiles, CI audits, append-only audit log |
| A09 Logging & Monitoring Failures | Structured logs with request IDs, audit log of auth and admin events |

## Event ingestion (Phase 2)

- **Authentication**: HMAC-SHA256 request signatures over method, path,
  timestamp and body hash (ADR-0006). Every failure (unknown key, revoked,
  expired, stale timestamp, bad signature) returns the same `401`; the reason
  is logged for operators only.
- **Tenant binding**: the credential determines the organisation; a client
  cannot write into another tenant or reference another tenant's assets.
- **Input handling**: 256 KiB body limit (checked before parsing), strict
  schema (unknown fields rejected), typed metadata per event type, metadata
  limits (64 keys, depth 4, 16 KiB, 2 048-char strings), RFC 3339 timestamps
  bounded in the future.
- **Abuse**: per-credential rate limit; idempotency keys and content
  fingerprints make replays inside the signature window harmless.
- **`APP_KEY` is a tier-0 secret**: together with a database copy it decrypts
  ingestion secrets. Rotating it requires re-issuing ingestion keys.
- **Redis outage**: ingestion's rate limiter fails open (requests remain
  authenticated and size-limited, and events are durably stored); all other
  limiters — including login brute-force protection — fail closed with `503`.
- **Synthetic data**: the simulator and demo seeder only use RFC 5737
  documentation address ranges and label events `synthetic: true`.

## Intelligence service (Phase 3)

- **Isolation**: KRATOS MIND runs on the Docker network `intelligence`
  (`internal: true`). It publishes no port, and Nginx does not route to it. Only
  the CORE containers (API, workers, scheduler, outbox relay, init) are
  attached to that network.
- **Service authentication**: CORE signs every request with HMAC-SHA256 over
  method, path, timestamp and body hash, using `MIND_SHARED_SECRET` (at least
  32 characters; MIND refuses to start otherwise). Signatures are compared in
  constant time, timestamps must be within ±300 s, and every failure returns
  the same `401`. Authentication runs **before** the body is parsed, so
  unauthenticated callers cannot probe the request schema. There is no nonce,
  but MIND is stateless, so a replayed request only repeats the same
  side-effect-free computation.
- **Minimal data**: CORE sends the event and pre-computed aggregate context.
  It sends no credentials, no tenant identifiers beyond the request, and no
  other tenants' data. MIND stores nothing.
- **Model artifact trust boundary**: models are scikit-learn pickles, and
  loading a pickle can execute code. MIND loads only from its own models
  directory, which is built in the image or held in a volume, and refuses any
  artifact whose SHA-256 or feature-schema version does not match its metadata.
  The checksum detects corruption and mismatched files. It is **not**
  protection against someone who can write to that directory, so treat write
  access to it as code execution.
- **No external AI**: detection is deterministic rules plus local scikit-learn
  models. No event data is sent to a third-party AI provider.
- **Fail-safe behaviour**: when MIND is unavailable, events are kept and
  retried (ADR-0007). Nothing is silently marked benign.
- **Tuning is bounded**: organisations can change rule thresholds only within
  the min/max set in configuration (`detections.manage`). Every change is
  written to the audit log.

## Known gaps (tracked)

- Multi-factor authentication: planned for Phase 6.
- Signed model artifacts (rather than a checksum alongside the file) and MIND secret rotation without downtime: Phase 6 candidates.
- Asymmetric (Ed25519) ingestion signatures to remove the shared secret: Phase 6 candidate (ADR-0006).
- PostgreSQL row-level security as a fourth isolation layer: evaluated in Phase 6 (ADR-0004).
- Production TLS configuration is left to the deployment environment; HSTS is
  emitted automatically on HTTPS requests.

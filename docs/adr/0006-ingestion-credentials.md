# ADR-0006: Ingestion credentials — HMAC request signing with encrypted secrets

- Status: Accepted (supersedes the ingestion-credential storage clause of ADR-0003)
- Date: 2026-10-23

## Context

ADR-0003 stated both that ingestion requests are HMAC-signed and that the
credential secret is stored "as an HMAC digest". These are incompatible: to
verify an HMAC signature the server must compute the same MAC, so it needs
the secret (or a value equivalent to it). Storing only a one-way hash would
force us back to sending the raw secret with every request.

Options considered:

| Option | Secret on the wire | Server storage | Replay / tamper protection |
| --- | --- | --- | --- |
| A. Bearer secret, store SHA-256 hash | Every request | Hash only | None beyond TLS |
| B. HMAC signing, store secret encrypted | Never | AES-256-GCM ciphertext (APP_KEY) | Body integrity + timestamp window |
| C. Asymmetric signatures (Ed25519) | Never | Public key only | Same as B |

## Decision

Option **B**:

- A credential is a public `key_id` (`krk_…`) plus a 40-byte random secret
  (`krs_…`), shown exactly once at creation.
- The secret is stored **encrypted** with Laravel's encrypter (AES-256-GCM,
  keyed by `APP_KEY`), never in plaintext, and is decrypted only in memory to
  verify a signature.
- Each request carries:
  - `X-Kratos-Key: <key_id>`
  - `X-Kratos-Timestamp: <unix seconds>`
  - `X-Kratos-Signature: v1=<hex HMAC-SHA256(secret, canonical)>`

  where `canonical = METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + hex(SHA-256(body))`.
- Timestamps outside ±300 seconds are rejected. Inside the window, a replayed
  request is harmless because ingestion is idempotent (idempotency keys and
  payload fingerprints; see ARCHITECTURE §5).
- Comparison is constant-time. Unknown keys, revoked or expired credentials,
  and bad signatures all return the same `401 invalid_signature` response.

Option C is the stronger long-term design (no shared secret at all) and is
recorded as a Phase 6 candidate. It was not chosen now because it makes the
client tooling heavier for every integrator.

## Consequences

- A database dump alone does not reveal usable secrets. A dump **plus**
  `APP_KEY` does, so `APP_KEY` is a tier-0 secret. Rotating `APP_KEY` requires
  re-issuing ingestion credentials (documented in SECURITY.md).
- Secrets never cross the network after creation, and bodies cannot be altered
  in transit without detection, even behind TLS-terminating proxies.

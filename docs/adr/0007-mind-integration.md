# ADR-0007: KRATOS MIND integration — stateless scoring, context from CORE

- Status: Accepted
- Date: 2026-10-24

## Context

Phase 3 adds detection: deterministic rules plus machine-learning anomaly
detection, in a Python service (FastAPI + scikit-learn). Most useful signals
are *contextual* ("14 failed logins from this IP in 15 minutes"), so scoring
one event needs recent history. Two designs were considered:

| | A. MIND queries PostgreSQL itself | B. CORE sends the event plus a computed context |
| --- | --- | --- |
| Tenant isolation | A second codebase must enforce `organisation_id` on every query | Stays in CORE's three tested layers; MIND never sees another tenant |
| Credentials | MIND needs DB credentials | MIND holds only the service secret |
| Feature parity | Training and serving might compute features differently | One Python function turns (event, context) into features for both |
| Cost | Fewer round trips | One SQL query per event in CORE |

## Decision

**B. MIND is stateless.** For each event CORE:

1. computes a fixed **context** with one tenant-scoped SQL query (e.g. failures
   from the same IP in 15 min, distinct usernames from the IP in 1 h, asset
   activity vs its 7-day hourly baseline, event-type/hour-of-day frequency);
2. sends `{event, context, asset, rules}` to `POST /internal/v1/analyse`,
   signed with HMAC-SHA256 using the scheme of ADR-0006 and a separate service
   secret;
3. stores MIND's response as `detection_results` and updates the event's
   `risk_score`, `verdict` and `threat_category`.

MIND applies the rules (thresholds come from CORE's `detection_rules` table,
so organisations can tune them), turns (event, context) into a feature vector
with the **same code used for training**, scores it with an Isolation Forest
and a robust statistical detector, explains the score, and combines everything
into a 0–100 risk score.

**Network**: MIND has no published port and is only attached to an internal
Docker network shared with CORE and the workers; Nginx cannot reach it.

**Failure behaviour**: if MIND is unreachable or returns 5xx, the job
*releases itself* with exponential backoff (capped at 5 min) for up to 6 hours
instead of consuming a retry attempt, so a MIND outage delays analysis but
does not dead-letter healthy events. Other exceptions still count toward the
5-attempt limit and dead-letter as before. A 4xx from MIND (contract
violation) is treated as a bug: it fails the attempt.

**Models** are trained reproducibly from a seeded synthetic dataset by
`python -m kratos_mind.training.train`. Each version records its artifact
SHA-256, feature schema, hyperparameters, dataset description and evaluation
metrics; MIND refuses to load an artifact whose hash does not match.

## Consequences

- Detection quality depends on the context CORE computes; adding a feature
  means changing the SQL, the Python feature schema (versioned), and retraining.
- Joblib artifacts are pickles. Integrity is checked by SHA-256 against the
  model's metadata; both live in the MIND image, which is a trust boundary. A
  signed model registry is a Phase 6 candidate.
- All published model metrics are measured on **synthetic data** and are
  labelled as such everywhere they appear.

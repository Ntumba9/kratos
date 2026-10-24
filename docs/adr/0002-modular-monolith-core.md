# ADR-0002: CORE, EYE and GUARD as modules of one Laravel application

- Status: Accepted
- Date: 2026-10-23

## Context
The specification names six components. EYE (ingestion) and GUARD (incidents)
both need transactional access to the same tenant data that CORE owns
(organisations, memberships, assets, events). Splitting them into separate
network services would require distributed transactions or eventual
consistency for operations such as "store event + enqueue analysis" and
"create incident + link events + audit".

## Decision
- CORE, EYE and GUARD are bounded modules inside one Laravel deployable,
  under `app/Domain/{Identity,Tenancy,Audit,Eye,Guard}`. Cross-module calls go
  through explicit service classes, not through each other's models.
- MIND is a separate Python service because it has a different runtime,
  dependency set (NumPy/scikit-learn), scaling profile (CPU-bound) and
  failure domain.
- COMMAND is a static SPA; AGENT is a separately distributed program.

## Consequences
- One database transaction can cover ingestion + outbox, or incident + links.
- Ingestion and API can still be scaled independently by running the same
  image with different process roles (php-fpm vs queue workers).
- If ingestion volume later requires it, EYE can be extracted behind its
  existing service interface and the outbox.

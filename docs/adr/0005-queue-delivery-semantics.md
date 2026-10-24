# ADR-0005: At-least-once processing with a transactional outbox

- Status: Accepted (implementation in Phase 2/5)
- Date: 2026-10-23

## Context
Events must not silently disappear if Redis or a worker fails. Redis-backed
Laravel queues cannot participate in a PostgreSQL transaction.

## Decision
- Ingestion writes the event and an `event_outbox` row in one transaction
  and only then acknowledges (`202`).
- A relay publishes outbox rows to Redis and marks them published; unpublished
  rows are retried, so Redis outages delay but do not lose work.
- Workers are idempotent: results are upserted on natural unique keys.
- Retries use exponential backoff with jitter; after the final attempt the job
  is recorded in `failed_jobs` and the event is marked `dead_lettered`, which
  is visible in the API and replayable.
- Workers handle `SIGTERM` gracefully (finish the current job, then exit).

## Consequences
The system provides **at-least-once** delivery with **idempotent effects**.
It does **not** provide exactly-once execution, and documentation must not
claim it.

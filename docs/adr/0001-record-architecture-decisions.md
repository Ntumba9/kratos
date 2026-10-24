# ADR-0001: Record architecture decisions

- Status: Accepted
- Date: 2026-10-23

## Context
KRATOS spans several runtimes and will evolve over six phases. Decisions need
a durable, reviewable record of *why*, not just *what*.

## Decision
Use lightweight ADRs (this format) in `docs/adr/`, numbered sequentially.
An ADR is never edited after acceptance except to change its status; a new
ADR supersedes it.

## Consequences
Reviewers can trace the rationale for structural choices. Writing an ADR is
required for changes to service boundaries, data ownership, authentication,
or delivery guarantees.

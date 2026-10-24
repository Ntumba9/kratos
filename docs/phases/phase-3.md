# Phase 3 — KRATOS MIND: detection rules, anomaly detection, risk scoring

**Scope:** a Python intelligence service that scores every security event with
deterministic rules, an Isolation Forest, and a statistical detector, explains
why, and assigns a risk score, verdict and threat category. Integrated into the
Phase 2 pipeline, with a Threat Intelligence view in the dashboard.

**Status:** Implemented and verified locally. Closing it out still needs a
green CI run (see *Open items*).

## Deliverables

| # | Deliverable |
| --- | --- |
| D1 | `mind/` FastAPI service (Python 3.12): HMAC-authenticated internal API, health, structured logs |
| D2 | Feature extraction shared by training and serving; versioned feature schema |
| D3 | Deterministic rules: brute force, credential stuffing, targeted account attack, lockout after failures, permission probing, API volume spike, off-hours privileged change, activity surge, abnormal timing, critical signatures |
| D4 | Isolation Forest + robust statistical detector, per-feature explanations, calibrated anomaly percentile |
| D5 | Risk scoring (documented formula), verdict, threat classification, deterministic explanation summary |
| D6 | Reproducible training: seeded synthetic dataset generator, training + evaluation CLI, model metadata with SHA-256, metrics, limitations (model card) |
| D7 | CORE: migrations (`detection_rules`, `model_versions`, `detection_results`, event analysis columns), context builder, MIND client, pipeline integration, outage behaviour (ADR-0007) |
| D8 | CORE API: detection rules (view/tune), detections, intelligence statistics, active model card |
| D9 | Dashboard: Threat Intelligence page, risk in the event explorer and detail |
| D10 | Tests: pytest, PHPUnit (incl. contract and real-service integration), Vitest, Playwright |

## Acceptance criteria

| ID | Criterion |
| --- | --- |
| AC1 | Every processed event has a risk score (0–100), verdict and threat category from MIND |
| AC2 | Each rule triggers on its scenario and not on normal traffic (unit tests per rule) |
| AC3 | The anomaly model is trained only from a reproducible script: same seed ⇒ same metrics |
| AC4 | Evaluation metrics (precision, recall, F1, ROC-AUC, PR-AUC, FPR, per-scenario recall) are recorded with the model and labelled as synthetic everywhere |
| AC5 | Every score is explained: triggered rules with observed values and thresholds, top contributing features with typical values |
| AC6 | MIND rejects unsigned / stale / wrongly signed requests and is not reachable from outside the internal network |
| AC7 | MIND unavailable ⇒ events wait and are analysed when it returns; none are lost or dead-lettered by the outage |
| AC8 | Re-processing an event (replay) replaces its detection results instead of duplicating them |
| AC9 | Organisations can disable rules and adjust thresholds within safe bounds (`detections.manage`); changes are audited |
| AC10 | Detections and intelligence statistics are tenant-isolated |
| AC11 | No paid AI API is used |

## Verification record

Executed 2026-10-24 on the Phase 1–2 machine (Windows 11, Docker Desktop,
repository in a OneDrive folder). Actual results only; all data is synthetic.

| Check | Result |
| --- | --- |
| PHPUnit, full suite (real PostgreSQL + Redis) | **196 passed, 1 414 assertions** |
| PHPUnit `Integration` suite (CORE ⇄ running MIND) | **3 passed** |
| Pint | pass (175 files) |
| pytest, full MIND suite | **41 passed** (1 third-party deprecation warning) |
| pytest after the explanation-units fix (`test_model`, `test_api`) | **26 passed** |
| ruff check / format | clean |
| Vitest / tsc / eslint (after the final UI change) | **23 passed** / clean / clean |
| Playwright, full suite | **6 passed, 1 failed**. The failure was a locator in the new test that matched two elements; after the fix, `intelligence.spec.ts` **1 passed** on its own. The full suite was not re-run. |
| Showcase capture (`playwright.showcase.config.ts`) | **1 passed**: signed ingestion → MIND scoring → console, screenshots in `docs/screenshots/` |
| Composer audit | no advisories |

### Acceptance criteria evidence

| ID | Evidence |
| --- | --- |
| AC1 | `AnalysisPipelineTest`; live: every showcase event has a risk score and verdict |
| AC2 | `mind/tests/test_rules.py`: each rule fires on its scenario and not on normal traffic |
| AC3 | Training with seed 42 produced the same artifact SHA-256 on repeated runs (`e0c7d6a5…`) |
| AC4 | Model metadata and the console card (`IntelligencePage.test.tsx` checks the synthetic label) |
| AC5 | `test_model.py` explanations; screenshot `event-explanation.png` |
| AC6 | `test_api.py` (unsigned, stale and wrong-secret requests get `401`, before schema validation); compose: `mind` publishes no ports and is only on the `internal` network |
| AC7 | Live drill: MIND stopped, 12 events ingested (`202`), held in `failed` with retry message (5 attempts, 0 `failed_jobs`); MIND restarted 11:27:23 → all 12 analysed by 11:30:14 (≈ 3 min, bounded by the 300 s backoff cap) |
| AC8 | `AnalysisPipelineTest`: replay replaces evidence instead of duplicating it |
| AC9 | `IntelligenceApiTest`: bounds validation, `detections.manage`, audit entries |
| AC10 | `IntelligenceApiTest`: cross-tenant requests get `404` |
| AC11 | No external AI dependency in `requirements.lock`; MIND has no outbound calls |

### Synthetic evaluation (test split, seed 42; not real-world accuracy)

Anomaly ROC-AUC 0.92; risk ROC-AUC 0.96; risk ≥ 40: precision 0.97,
recall 0.89, false-positive rate 0.87 %. The generator and the rules were
designed together, and the thresholds were tuned on a separate validation
split. These figures show internal consistency, not field performance.

## Defects found and fixed during verification

1. Unauthenticated requests to MIND got `422` (schema leak) instead of `401`.
2. False-positive rate of 11.8 % at risk ≥ 40 on the validation split. Fixed
   with per-type baselines, an anomaly contribution that starts at p99, and a
   retuned timing threshold.
3. Rule scores ignored rule severity (capped by `INTENSITY_HEADROOM`).
4. Activity surge fired on cold-start assets (feature schema v2).
5. `sync` queue cleared the tenant context, so replays returned `500`.
6. Explanations showed log-transformed feature values ("2.7081 vs typical 0")
   instead of counts. They now report original units (found during the
   showcase capture).
7. CI: the CORE job never set `REDIS_PORT`, so the health test reached the
   development port and got `503`.

## Open items (unverified)

- **CI has not passed on GitHub.** Both pushed runs failed in the CORE job
  (defect 7). The fix is local and not yet pushed, and the MIND and e2e CI
  jobs have not yet run green on GitHub.
- The full Playwright suite was not re-run after the locator fix.
- `docker-compose.prod.yml` and `infra/oracle/` were syntax-checked only.
  They have never been run, and no backend is deployed.
- `npm audit` and the production build were not re-run after the final
  frontend change.

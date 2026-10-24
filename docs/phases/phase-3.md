# Phase 3 — KRATOS MIND: detection rules, anomaly detection, risk scoring

**Scope:** a Python intelligence service that scores every security event with
deterministic rules, an Isolation Forest, and a statistical detector, explains
why, and assigns a risk score, verdict and threat category. Integrated into the
Phase 2 pipeline, with a Threat Intelligence view in the dashboard.

**Status:** In progress.

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

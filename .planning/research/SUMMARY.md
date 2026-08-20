# Project Research Summary

**Project:** UP-Fix — Smart Maintenance Request System (University of Phayao)
**Domain:** CMMS / AI-assisted maintenance request intake and triage (PHP 8.2+/SQL Server, async worker, LINE OA + LIFF, Claude vision+text classification)
**Researched:** 2026-08-18
**Confidence:** MEDIUM (architecture is HIGH — validated against an authoritative pre-existing SPEC.md; stack/features/pitfalls are MEDIUM — cross-checked web sources, no official Microsoft/LINE doc verification pass yet)

## Executive Summary

UP-Fix is a reactive maintenance-request CMMS distinguished from every commercial competitor (UpKeep, MaintainX, Limble, Oxmaint) by one core thesis: reporters submit a photo + one free-text sentence via LINE chat with zero category/trade selection, and an AI pipeline does the classification, hazard detection, duplicate matching, and materials prediction that a dropdown-driven form would normally require a human to do. Experts build this class of system as two coupled halves: (1) a textbook transactional-outbox/DB-as-queue pattern (SQL Server `job_queue` standing in for Redis/BullMQ, appropriate at this volume — 500 tickets/day, well under the ~100-200 jobs/sec ceiling where table-queues start to strain), and (2) a cost-ordered, confidence-tiered AI pipeline (cheap deterministic steps first, expensive Sonnet calls last, human-in-the-loop routing by confidence with an independent hard override for safety hazards). SPEC.md already specifies this correctly at a level of detail unusual for a fresh research pass — this research validates rather than redesigns it, and the roadmap should treat SPEC.md as the primary source of truth with this summary as sequencing/risk guidance layered on top.

The recommended approach is to sequence work around one governing constraint: the AI classification pipeline is not just a component, it is a quality-gated checkpoint (P1/hazard recall ≥95% is non-negotiable) that everything downstream — dashboard, technician UI, analytics — depends on for meaningful data. Building dashboard/mobile UI before the AI pipeline clears its eval bar risks expensive rework if the model can't hit the bar. The stack is largely locked by university IT constraints (PHP, SQL Server, no infra add-ons, no CDNs, vendored JS) and research mostly confirms exact versions and flags two placeholder errors to fix immediately: the `.env.example` model IDs (`claude-sonnet-4`/`claude-haiku-4`) are not valid — must be `claude-sonnet-5`/`claude-haiku-4-5` — and `pdo_sqlsrv` 5.13.0 requires PHP 8.3+, conflicting with SPEC's stated 8.2+ floor unless the university confirms 8.3.

The key risks cluster into two categories the spec's existing mitigations don't fully close: (1) **invisible failure modes** — P1 recall, PII redaction recall, and confidence calibration all fail silently in production because the existing monitoring (correction-rate dashboard, "AI got this wrong" button) only catches complaints a human happens to notice, not systematic misses; these need dedicated recurring audit processes, not just launch-time eval. (2) **data-foundation dependencies that gate AI quality but look like "just seed data"** — the authoritative buildings/assets list (Open Question 3) and the Smart Services legacy import (Open Questions 1-2) both block trustworthy eval numbers and the M-2 director-persuasion analytics feature respectively, and both have unresolved external dependencies that should be chased in parallel with Layer 0 engineering work, not treated as late-phase integration tasks.

## Key Findings

### Recommended Stack

The stack is mostly locked by SPEC.md (PHP 8.2+, `pdo_sqlsrv`, vanilla JS with no build step, LINE Messaging API + LIFF, Claude via Guzzle) — this research validates exact versions rather than proposing alternatives. The most consequential finding is a version-floor conflict: `pdo_sqlsrv` 5.13.0 (the only actively-maintained driver release) requires PHP 8.3+, not 8.2 as SPEC.md's floor states — this must be resolved with university IT before `src/Db/Connection.php` is written. A second correctness issue: SPEC.md's `.env.example` model IDs are placeholder strings that don't exist on the current API (`claude-sonnet-4`, `claude-haiku-4`) — real IDs are `claude-sonnet-5` and `claude-haiku-4-5`, confirm again via `GET /v1/models` at implementation time.

**Core technologies:**
- PHP 8.2/8.3 + `pdo_sqlsrv` 5.13.0 + MS ODBC Driver 18 — only supported path to SQL Server from PHP; confirm PHP 8.3 availability with IT before locking the floor
- Guzzle ^7.10 (not the new 8.0.x) — HTTP client for Anthropic + LINE; 7.x is the safer pin until the PSR ecosystem catches up to Guzzle 8
- Chart.js 4.5.x UMD, vendored locally (never CDN) — dashboard charts, matches the no-build-step and no-third-party-PII-adjacent-dependency constraints
- LIFF SDK v2.29.x via CDN edge path — technician mobile UI inside LINE's in-app browser, LINE keeps v2 backward compatible so this is low-risk
- `opis/json-schema` ^2.6, `firebase/php-jwt` ^7.1, `monolog/monolog` ^3.10 — widen SPEC.md's pins slightly; server-side schema validation of every AI JSON response is non-negotiable regardless of the model's structured-output guarantee
- Imagick (not GD — GD cannot decode HEIC at all) — PII redaction, HEIC→JPEG; confirm the ImageMagick HEIF delegate (`libheif`) is installable on the target server before committing to the HEIC edge case
- PHPUnit ^11.0 (not SPEC's ^10.5, not the newer 13.x which needs PHP 8.4+)
- `linecorp/line-bot-sdk` — not in SPEC.md's composer.json; recommended addition to remove hand-rolled HMAC webhook signature verification from the security-sensitive path

### Expected Features

SPEC.md's feature set already matches or exceeds every table-stakes CMMS feature found in commercial research (UpKeep, MaintainX, Limble, SAP, Oxmaint/Urbest education-vertical vendors), with one large and intentional scope boundary: no preventive-maintenance (PM) scheduling module — UP-Fix is a reactive-request system only, and this should be an explicit roadmap placeholder so CMMS-literate stakeholders know it was considered and deferred, not missed.

**Must have (table stakes, all already in SPEC.md §14 "Must have"):**
- Work order lifecycle, priority/urgency triage (P1-P4 + hazard force-escalation), asset linkage, staff triage dashboard, mobile technician view, automatic routing, SLA tracking, requester status visibility, photo/text intake, append-only audit trail, duplicate detection with reversible merge

**Should have (differentiators — what makes this AI-assisted, not just a digital form):**
- Zero-domain-knowledge intake (photo + one sentence, no category dropdown) — the core product thesis and #1 friction point removed relative to every competitor surveyed
- Mandatory `evidence` citation on every AI classification — matches AI-triage explainability best practice, exceeds what commercial CMMS AI upsells expose
- Confidence-tiered routing + hazard override immune to model confidence — stronger guarantee than typical AI-incident-triage systems
- Thai-text-aware duplicate detection (trigram/Dice, no tokenizer/vector DB) — genuinely non-obvious, domain-correct choice since Thai lacks word-spacing
- Predicted materials list, repeat-repair/replace-vs-repair analytics, fairness enforcement (AC-10) — no direct precedent found in any surveyed CMMS; treat fairness as an automated regression test, not a policy statement

**Defer (v2+, already correctly excluded per SPEC.md §14 "Won't have"):**
- Preventive maintenance scheduling module, inventory/spare-parts integration, predictive maintenance/IoT, offline technician mode, energy-meter correlation
- Additionally flagged as risks to keep explicitly excluded: individual technician performance scoring (labor/legal risk), fully autonomous AI decisions without human approval, native mobile app, vector DB/embeddings for dedup, real-time websocket updates (polling is correct at this scale)

### Architecture Approach

The system is a **DB-as-queue (transactional outbox) pattern** feeding a **cost-ordered, confidence-tiered AI pipeline with a human-in-the-loop escape hatch** — the front controller never calls the LLM inline (writes rows + enqueues, returns in <400ms), a `job_queue` table with atomic `UPDATE...OUTPUT` claiming replaces Redis/BullMQ (appropriate at 500 tickets/day, ~1000x under the pattern's practical throughput ceiling), and `bin/worker.php` runs stages ordered cheap→expensive: redact (Imagick, local) → quality gate (Haiku) → classify (Sonnet) → duplicate check (SQL filter → trigram/Dice → Haiku adjudication) → route (pure deterministic PHP, no LLM) → notify.

**Major components:**
1. Front controller (`public/index.php`) — auth/RBAC/idempotency/rate-limit, validate, persist, enqueue, return fast; never touches the LLM
2. `bin/worker.php` + `Handlers/*` + `Ai/*` classes — the staged AI pipeline; each stage is a discrete class so `bin/eval.php` and production share one code path
3. `Domain/Routing.php` + `Domain/Sla.php` — pure deterministic PHP, zero dependency on `Ai/*`, independently unit-testable from day one (this enables a parallel build track)
4. Staff dashboard / triage UI + Technician LIFF UI — pure read/write HTTP/JSON clients against the same tables the worker populates, no separate read model or cache layer needed at this scale
5. `Integration/SmartServicesImporter.php` — one-off/scheduled ETL from the legacy system, off the live request path but on the critical path for eval-dataset assembly and repeat-repair analytics

The architecture research's most load-bearing output is a **build order** (Layer 0 foundations → Layer 1 core intake → Layer 2 job queue infra + Layer 4a routing engine in parallel → Layer 3 AI pipeline with an eval go/no-go gate → Layer 4b wire routing into real pipeline → Layer 5 LINE channel + Layer 6 staff dashboard → Layer 7 technician LIFF → Layer 8 analytics), which directly informs the roadmap phase suggestions below.

### Critical Pitfalls

1. **Vision-LLM bounding boxes as the sole PII redaction layer** — models miss faces in poor lighting/angles with no independent check; bias `Redactor.php` toward over-blurring (15-20% margin expansion) and evaluate redaction recall as its own metric, separate from classification accuracy, before ever marking `redacted=1` as a compliance guarantee.
2. **P1 recall measured once at launch with no production recall signal** — the correction-rate dashboard only catches precision failures (someone complains); recall failures (missed hazards) are invisible by construction. Requires a recurring manual audit-sampling runbook, not just a one-time `bin/eval.php` pass.
3. **Model's self-reported `confidence` treated as a calibrated probability** — the 0.75/0.50 routing thresholds are round-number guesses until a calibration curve (accuracy per confidence bucket) is plotted against the 300-record labelled test set; recalibrate on every prompt version change.
4. **Anti-hallucination guardrail depends on an authoritative building/asset list that doesn't exist yet** — Open Question 3 (building code source) is a hard blocking dependency; an incomplete seed silently inflates human-triage rate for reasons unrelated to model quality and corrupts every eval number downstream.
5. **Non-idempotent job handlers cause double AI billing and duplicate reporter notifications on crash-retry** — atomic claim prevents concurrent double-processing but not sequential double-processing after a crash; idempotency must be checked by ticket_id+prompt_version existence, not assumed from safe claiming alone.

## Implications for Roadmap

Based on combined research (architecture's Layer 0-8 build order is the primary driver, cross-checked against feature dependencies and pitfall prevention points), suggested phase structure:

### Phase 1: Foundations & Data Layer
**Rationale:** Everything blocks on schema, DB connection, and — critically — the authoritative buildings/assets reference data (Pitfall 4). Start the university IT conversation about building code source and PHP 8.2 vs 8.3 (stack version-floor conflict) immediately; it's off the critical path for engineering but the slowest external dependency to unblock.
**Delivers:** DB schema/migrations (all SPEC §5 tables), `Db/Connection.php` with deadlock retry, Env/Logger, ticket_no generator, seeded (or confirmed-placeholder) buildings/assets reference data
**Addresses:** Data foundation table stakes; unblocks every AI-dependent feature
**Avoids:** Pitfall 4 (hallucination guardrail depending on incomplete reference data) — resolve or explicitly flag as placeholder before eval work starts

### Phase 2: Core Ticket Intake (web channel, no AI)
**Rationale:** Proves the request/response API contract and idempotency/rate-limit middleware before any async or AI complexity is layered on.
**Delivers:** `POST /tickets` (text+image multipart), `GET /tickets/{id}`, idempotency + rate-limit middleware, RBAC/JWT auth scaffolding (build once here, reuse in dashboard/LIFF phases)
**Addresses:** Photo/text attachment intake (table stakes), audit trail scaffolding
**Avoids:** Anti-Pattern 1 (never calling the LLM inline) — enforce the "controller only persists+enqueues" contract from the start

### Phase 3: Async Job Queue Infrastructure
**Rationale:** Must exist before any AI pipeline stage can run; this is also where idempotency (Pitfall 6) belongs structurally — in a shared handler base/decorator, not left to each handler author.
**Delivers:** `job_queue` table + atomic claim SQL, `bin/worker.php` polling loop, `bin/scheduler.php` stale-lock recovery, idempotency-check decorator for handlers
**Uses:** `pdo_sqlsrv` atomic `UPDATE...OUTPUT` claim pattern, filtered index on `(status, run_after)`
**Implements:** DB-as-queue architectural pattern

*(Parallel track alongside Phase 3): Routing engine (`Domain/Routing.php`) against stub input — zero AI dependency, independently unit-testable, wired into the real pipeline in Phase 5.*

### Phase 4: AI Classification Pipeline (highest-risk, dedicated phase)
**Rationale:** This is a quality-gated checkpoint, not just a component — P1 recall ≥95% is a non-negotiable go/no-go bar. Nothing downstream produces a meaningful demo until this clears `bin/eval.php` against the labelled dataset.
**Delivers:** `AnthropicClient` → `Redactor` (Imagick, with EXIF/thumbnail stripping — Pitfall 8) → `ImageQualityGate` (Haiku) → `Classifier`+`SchemaValidator` (Sonnet) → `DuplicateFinder` (SQL filter → trigram/Dice → Haiku adjudication, with hazard-exclusion hard guard — Pitfall 5); calibration curve plotted before thresholds finalized (Pitfall 3); redaction-recall eval as a separate metric (Pitfall 1)
**Addresses:** AI classification with evidence, hazard force-escalation, duplicate detection (all P1 features)
**Avoids:** Pitfalls 1, 3, 5, 8 — all four are structurally scoped to this phase per the pitfalls-to-phase mapping

### Phase 5: Routing Wire-In + Confidence-Tiered Dispatch
**Rationale:** Merges the parallel-built routing engine (Phase 3 track) with real AI pipeline output (Phase 4); this is where the safety_hazard hard override and confidence-tiered human triage queue become real.
**Delivers:** Confidence-tiered routing (§4.4), safety_hazard hard override, technician assignment against real classified tickets
**Addresses:** Deterministic job routing, confidence-tiered human triage queue

### Phase 6: LINE Channel + Staff Triage Dashboard
**Rationale:** Thin webhook receiver can start earlier (just another caller of the ticket-creation path), but full conversational UX (quick replies, duplicate confirmation, push notifications) and the dashboard both depend on Phases 4-5 producing real classified, queued tickets for humans to act on.
**Delivers:** LINE OA intake with full conversational UX, staff dashboard (job queue, triage queue, assignment)
**Addresses:** Status visibility for requester, staff triage/assignment dashboard (table stakes)

### Phase 7: Technician LIFF UI
**Rationale:** Needs Phase 5 (assignment) and Phase 6 (someone assigning jobs) to have anything to display.
**Delivers:** Job detail view, accept job, after-photo close flow with P1/P2 enforcement (AC-7)
**Addresses:** Mobile-friendly technician view (table stakes)

### Phase 8: Analytics & Legacy Import
**Rationale:** Repeat-repair analysis (the M-2 director-persuasion feature) is close to worthless without either meaningful live volume or the Smart Services legacy import — start the import's data-access conversation early (parallel to Phase 1) even though the import code itself can land here.
**Delivers:** `SmartServicesImporter`, repeat-repair/replace-vs-repair analytics, SLA report by team, monthly AI cost dashboard
**Addresses:** Repeat-repair analytics (P1 for leadership buy-in), legacy import

### Phase Ordering Rationale

- The AI pipeline (Phase 4) is treated as a dedicated, gated phase rather than "component 4 of 8" because it's the one part of the system with an external, non-negotiable quality bar (P1 recall ≥95%) that can only be measured against a labelled dataset — discovering late that the model can't hit that bar after dashboard/mobile UI are built against its output shape is expensive to unwind (Anti-Pattern 5).
- Routing is deliberately split into a parallel-buildable stub phase (alongside Phase 3) and a wire-in phase (Phase 5) because it has zero AI dependency in isolation but can't be proven end-to-end until the AI pipeline exists — treating it as sequential-only would create an artificial bottleneck.
- Data-foundation work (buildings/assets reference data, Smart Services access) is placed as early as possible and flagged as an external dependency to start chasing in parallel with Phase 1 engineering, because it's off the team's critical path to *start* but is the single slowest thing to *resolve*, and it gates trustworthy eval numbers in Phase 4.
- PII redaction pitfalls (bounding-box recall, EXIF stripping) are scoped to Phase 4 specifically because SPEC.md's own pipeline ordering (redact before quality-gate, before classify) means redaction correctness must be solid before any image is ever served to a non-admin role — this can't be deferred to a later hardening pass.

### Research Flags

Phases likely needing deeper research during planning (`/gsd:plan-phase --research-phase <N>`):
- **Phase 1 (Foundations):** SQL Server-specific patterns (deadlock retry, connection pooling reset behavior, `pdo_sqlsrv`/PHP version matrix) are MEDIUM-confidence web-sourced; verify against Microsoft Learn docs before implementation
- **Phase 4 (AI Pipeline):** Confidence calibration methodology, redaction-recall eval design, and Thai-specific trigram/Dice tuning are the highest-uncertainty, highest-stakes parts of the whole system — worth a dedicated research pass on evaluation methodology specifically (not just the API calls)
- **Phase 8 (Legacy Import):** Blocked on unresolved open questions (same SQL Server instance? Linked Server vs CSV ETL?) — needs its own scoping research once Open Questions 1-2 are answered by the university

Phases with standard patterns (skip research-phase):
- **Phase 2 (Core Intake):** Standard PHP MVC + multipart upload + idempotency middleware — well-documented, SPEC.md already fully specifies the contract
- **Phase 3 (Job Queue):** DB-as-queue with atomic claim is a well-established, thoroughly cross-referenced pattern (SPEC.md's SQL is already the correct idiom)
- **Phase 6/7 (Dashboard/LIFF UI):** Standard fetch()/JSON/JWT frontend patterns against an already-defined API surface

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | MEDIUM | Anthropic model/API facts are HIGH (curated `claude-api` skill); Composer package versions and `pdo_sqlsrv`/PHP compatibility are MEDIUM (Packagist/web search, changes frequently — re-verify with `composer show -a` at implementation time) |
| Features | MEDIUM | Cross-checked across multiple independent commercial CMMS and AI-triage sources, but no official standards body for this domain; SPEC.md itself was the primary validation baseline |
| Architecture | HIGH (structural) / LOW-MEDIUM (supplementary patterns) | SPEC.md is an authoritative pre-existing design; this research validates/sequences it rather than inventing from scratch. Supporting industry-pattern citations (job-queue throughput ceilings, HITL queue share benchmarks) are general web search, not curated docs |
| Pitfalls | MEDIUM | Cross-referenced multiple independent sources per topic, but no official Anthropic/Microsoft docs consulted for SQL Server locking specifics — verify during implementation |

**Overall confidence:** MEDIUM-HIGH — the architecture and feature scope are unusually well-grounded because SPEC.md is a detailed pre-existing design; the main uncertainty is in exact third-party version pins and unresolved external dependencies (university IT answers), not in the overall approach.

### Gaps to Address

- **PHP 8.2 vs 8.3 floor conflict with `pdo_sqlsrv` 5.13.0:** must be resolved with university IT before `src/Db/Connection.php` is written (Phase 1) — if only 8.2 is available, a different (older) `pdo_sqlsrv` release must be pinned and re-verified.
- **Authoritative buildings/assets reference data source (Open Question 3):** blocking dependency for trustworthy AI eval numbers; start this conversation in Phase 1, treat as a hard gate before the Phase 4 eval run that decides go/no-go on the AI pipeline.
- **Smart Services legacy system location/access (Open Questions 1-2):** determines whether the importer can use a simple cross-database JOIN or needs a Linked Server/CSV ETL — materially changes `SmartServicesImporter`'s design; also determines whether the eval dataset and repeat-repair analytics have real historical grounding or launch with an empty-history demo state.
- **Deployment platform (Linux/Nginx vs Windows/IIS, Open Question 7):** affects Imagick/HEIC delegate installability (bigger risk on Windows) and how `bin/worker.php`/`bin/scheduler.php` are supervised (systemd vs Task Scheduler) — resolve before Phase 1 infrastructure decisions are finalized.
- **No rollout/training phase exists yet in the roadmap-to-be:** pitfalls research flags CMMS adoption failure (people, not tech) as a known category of failure with no corresponding phase — recommend adding a rollout/change-management phase before or alongside Phase 6-7, even if lightweight for a hackathon-scale MVP.

## Sources

### Primary (HIGH confidence)
- `claude-api` skill (curated) — Anthropic current model IDs, pricing, Haiku 4.5 vision support, PHP SDK existence, structured-output/tool-use API surface
- SPEC.md v2.0 (2026-08-18) and `.planning/PROJECT.md` — authoritative internal source, used throughout all four research files as the baseline validated against, not an external source

### Secondary (MEDIUM confidence)
- Microsoft Learn / Microsoft Community Hub — `pdo_sqlsrv` 5.13.0 release notes, connection pooling, `MSSQLSERVER_1205` deadlock docs
- developers.line.biz official docs — webhook signature verification, LIFF SDK release notes, 2-second webhook ack requirement
- MSSQLTips, SQLServerCentral, Erik Darling's queue-design series — SQL Server table-queue claim pattern (`READPAST`/`UPDLOCK`/`ROWLOCK`)
- UpKeep, MaintainX, SAP, IBM, Limble, Oxmaint, Urbest — commercial/education-vertical CMMS feature landscape
- Augment Code, DevRev, Beagle, Moveworks, Underdefense, Panther, PagerDuty — AI ticket/incident triage best practices (confidence-threshold routing, explainability, alert fatigue)
- Packagist/direct WebFetch — Composer package versions (guzzlehttp/guzzle, opis/json-schema, firebase/php-jwt, monolog/monolog, phpunit/phpunit) — re-verify with `composer show -a` at implementation time

### Tertiary (LOW confidence, needs validation)
- Medium/DEV Community posts on Postgres-as-queue throughput ceilings (~100-200 jobs/sec) — generalized to SQL Server by analogy, not SQL-Server-specific
- AWS ML Blog, arXiv (PII redaction pipeline ordering, face/plate anonymization) — general pattern guidance, not domain-specific to Thai-language maintenance photos
- Stanford CS231n, Ego4D papers — de-identification pipeline discussion, general computer vision context not specific to this project's model (Claude vision)
- MetaClean blog posts — EXIF/embedded-thumbnail metadata risk (Pitfall 8), consumer-facing source, verify Imagick `stripImage()` behavior empirically for the specific build in use

---
*Research completed: 2026-08-18*
*Ready for roadmap: yes*

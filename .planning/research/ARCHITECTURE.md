# Architecture Research

**Domain:** AI-assisted maintenance-request intake/triage system (multimodal AI classification + async background processing + chat-bot intake + staff dashboard + mobile field-tech UI), PHP/SQL Server stack
**Researched:** 2026-08-18
**Confidence:** HIGH for structural validation (SPEC.md is an authoritative, pre-existing detailed design — this report validates and sequences it, not invents from scratch) / LOW-MEDIUM for the supplementary industry-pattern claims (general web search, not curated docs — see Sources)

**Scope note:** SPEC.md §3, §5, §6, §7 already specify a complete architecture. This document (a) confirms that design against how comparable systems are typically structured, (b) calls out the few places the SPEC's ordering differs from the naive default and explains why that's still correct, and (c) turns the dependency graph into a concrete build order for the roadmap. It intentionally does not re-derive the schema or API — see SPEC.md for the source of truth.

## Standard Architecture

### System Overview (validated against SPEC.md §3)

```
┌───────────────────────────────────────────────────────────────────────┐
│  CHANNELS (reporter / technician / staff surfaces)                    │
│  ┌───────────┐   ┌──────────────┐   ┌──────────────┐                  │
│  │  LINE OA  │   │ LIFF tech UI │   │ Web Dashboard│                  │
│  │(reporters)│   │ (technician) │   │(triage/mgr)  │                  │
│  └─────┬─────┘   └──────┬───────┘   └──────┬───────┘                  │
│        │ webhook        │ fetch()          │ fetch()                 │
└────────┼────────────────┼──────────────────┼──────────────────────────┘
         └────────────────┴─────────┬────────┘
                                     ▼
┌───────────────────────────────────────────────────────────────────────┐
│  SYNCHRONOUS LAYER — public/index.php front controller                │
│  Router → Middleware (Auth/RBAC/Idempotency/RateLimit) → Controller   │
│  Contract: never calls the LLM inline. Writes rows + enqueues a job,  │
│  then returns. p95 < 400ms.                                           │
└────────────────────────────┬──────────────────────────────────────────┘
                              │ PDO (sqlsrv)
                              ▼
┌───────────────────────────────────────────────────────────────────────┐
│  PERSISTENCE — SQL Server                                             │
│  tickets · ticket_media · ai_classifications · ticket_events(audit)   │
│  job_queue (the "message broker") · buildings/assets/technicians      │
│  + storage/media/ (filesystem, outside webroot)                       │
└────────────────────────────┬──────────────────────────────────────────┘
                              │ polling claim (atomic UPDATE...OUTPUT)
                              ▼
┌───────────────────────────────────────────────────────────────────────┐
│  ASYNCHRONOUS LAYER — bin/worker.php (≥2 processes) + bin/scheduler.php│
│  Job pipeline per ticket, cost-ordered cheap→expensive:                │
│  1. redact_media   (Imagick, local, no LLM cost)                      │
│  2. quality gate   (Haiku, cheap)                                     │
│  3. classify        (Sonnet, expensive — runs only on usable images)  │
│  4. find_duplicate  (SQL filter → trigram/Dice → Haiku adjudication)  │
│  5. route           (pure PHP — Routing.php, no LLM)                  │
│  6. notify_line     (push result back to reporter/staff)              │
└───────────────────────────────────────────────────────────────────────┘
```

This is a textbook **transactional-outbox / DB-as-queue** pattern layered under a **staged AI pipeline with a human-in-the-loop escape hatch**. Both halves are well-established patterns for this domain; the notable choice is putting the queue *in* the system of record (SQL Server) instead of a separate broker — appropriate here because volume is bounded (500 tickets/day, burst 50/min) and it removes an operational dependency the university doesn't want to run.

### Component Responsibilities

| Component | Responsibility | Talks to |
|-----------|----------------|----------|
| Front controller (`public/index.php`) | Auth/RBAC/idempotency/rate-limit, validate input, write rows, enqueue jobs, return fast | SQL Server (PDO), `job_queue` (write only) |
| `LineWebhookController` | Verify LINE signature, translate LINE events → same ticket-creation path, dedupe via `webhookEventId` | Front controller's ticket creation logic, `idempotency_keys` |
| `job_queue` table | Durable work list; replaces Redis/BullMQ | Written by controllers, claimed/updated by worker |
| `bin/worker.php` | Poll → atomically claim → dispatch to a `Handlers/*` class → mark done/failed/retry | `job_queue`, `AnthropicClient`, `LineClient`, `tickets`/`ai_classifications` |
| `bin/scheduler.php` | Cron-driven housekeeping: reset stale `running` locks, SLA escalation, auto-close, purge `_raw/` images | `job_queue`, `tickets`, filesystem |
| `Ai/Redactor.php` | Blur PII regions returned by the model, write a new `redacted=1` file | `storage/media/_raw/` → `storage/media/{yyyy}/{mm}/` |
| `Ai/ImageQualityGate.php` | Reject unusable images before the expensive classification call | `AnthropicClient` (Haiku) |
| `Ai/Classifier.php` + `SchemaValidator.php` | Produce and validate the structured classification (§4.3) | `AnthropicClient` (Sonnet), `ai_classifications` |
| `Ai/DuplicateFinder.php` | 3-layer duplicate check (SQL → trigram/Dice → Haiku) | `tickets`, `ai_classifications.text_signature` |
| `Domain/Routing.php` | Deterministic technician assignment | `technicians`, `tickets` — no AI |
| `Domain/Sla.php`, `TicketState.php` | Business-day SLA math, legal state transitions | Pure functions over ticket rows |
| Staff dashboard (`dashboard.html`, `triage.html`) | Human review/override surface for AI output | `/api/v1/tickets*`, `/analytics/*` |
| Technician LIFF (`tech.html`) | Field execution surface | `/api/v1/tickets/{id}`, `/api/v1/tickets/{id}/media`, `/api/v1/tickets/{id}/events` |
| `Integration/SmartServicesImporter.php` | One-off/scheduled ETL from the legacy system | Legacy DB (linked server or CSV) → `tickets`/`assets` (historical) |

## Recommended Project Structure

SPEC.md §8.4 already specifies the full project layout (`src/Http`, `src/Domain`, `src/Ai`, `src/Integration`, `src/Queue`, `src/Support`, `src/Db`, `bin/`, `database/migrations`, `public/`, `storage/`, `tests/`). That structure is standard for a layered PHP MVC + worker app and needs no changes. The one structural addition worth calling out for the roadmap:

- **`src/Domain/Routing.php` and `src/Domain/Sla.php` have zero dependency on `src/Ai/*`.** They operate on plain scalars (category, skills, zone, dates) and can be fully unit-tested with fixture data before any AI code exists. Treat `src/Domain/` as an independently buildable/testable module from day one — this is what makes an early, parallel routing-engine phase possible (see Build Order).
- **`src/Ai/*` should be built behind a narrow interface** (`Classifier::classify(TicketInput): ClassificationResult`) so `bin/eval.php` (§11.2) and the worker handler call the same code path — this is implied by SPEC.md's project structure but worth stating explicitly as an architectural rule for whoever plans the AI phase.

## Architectural Patterns

### Pattern 1: DB-as-queue with atomic claim (SPEC.md §5.7)

**What:** A `job_queue` table replaces Redis/BullMQ. Workers claim rows with a single atomic statement, never `SELECT` then `UPDATE`.
**When to use:** Bounded throughput (SPEC's own research-validated ceiling for this pattern is ~100–200 jobs/sec before lock contention — three orders of magnitude above UP-Fix's 500/day), and when avoiding an extra ops dependency matters more than raw throughput.
**Trade-offs:** Simpler ops, one fewer service to run/monitor/secure; costs a small amount of DB load from polling and requires disciplined lease/stale-lock handling that a real broker gives you for free.
**Example (already in SPEC.md §5.7, confirmed as the correct idiom):**
```sql
UPDATE TOP (1) job_queue WITH (ROWLOCK, READPAST, UPDLOCK)
SET status = 'running', locked_by = @workerId, locked_at = SYSUTCDATETIME(),
    attempts = attempts + 1, updated_at = SYSUTCDATETIME()
OUTPUT inserted.id, inserted.job_type, inserted.payload, inserted.attempts
WHERE status = 'pending' AND run_after <= SYSUTCDATETIME();
```
This is the SQL Server equivalent of Postgres `SELECT ... FOR UPDATE SKIP LOCKED` — same pattern, correct dialect.

### Pattern 2: Cost-ordered AI pipeline stages

**What:** Order pipeline stages from cheapest/most-deterministic to most-expensive, so a bad input is rejected before money is spent on it. SPEC.md's order is redact (local, ~free) → quality gate (Haiku, cheap) → classify (Sonnet, expensive) → duplicate (mostly local trigram math, Haiku only for the borderline band) → route (pure PHP, free).
**When to use:** Any multimodal pipeline with a per-call cost gradient and a hard per-ticket cost budget (§9.4: ≤ THB 0.60/ticket).
**Trade-offs:** Redacting before quality-gating means you pay the (cheap) Imagick cost even on images that get rejected as blurry/irrelevant — but that's the right trade for a PDPA system, because it guarantees no unredacted image is ever visible in any UI, even transiently, regardless of what happens downstream. Don't reorder this to "quality gate first" purely for cost reasons; it would create a window where an unredacted face-containing image could be surfaced before rejection logic runs.
**Note:** industry examples of PII pipelines often redact *after* classification because the classifier needs full context. SPEC.md deliberately inverts this for privacy-by-default; that's a defensible domain-specific choice, not an oversight — evidence needed for hazard/category classification (exposed wire, water stain, cracked tile) essentially never lives in the pixelated face/plate regions.

### Pattern 3: Confidence-tiered human-in-the-loop routing

**What:** Route AI output into auto-assign / needs-review / human-triage lanes by confidence, with an independent hard override (`safety_hazard=1` forces P1 regardless of confidence) rather than relying on a single confidence number.
**When to use:** Whenever a wrong-but-confident model output is unacceptable in a specific category (safety) even if acceptable elsewhere (routine cosmetic requests).
**Trade-offs:** Two independent signals (confidence *and* a hazard flag) catch failure modes a single calibrated score misses, at the cost of an extra rule to maintain. Industry-observed guidance: a well-calibrated triage queue holds ~5–10% of volume; SPEC.md's §11.2 target of ≤20% human-triage share is a reasonable upper bound for a v1 model, with room to tighten as the eval-tracked correction rate improves.
**Example:** SPEC.md §4.4's three-tier table plus the `safety_hazard` hard override in §4.2 is exactly this pattern; no changes recommended.

## Data Flow

### Ticket creation → classification → assignment (the critical flow)

```
Reporter (LINE or web)
    │  POST /tickets  or  webhook event
    ▼
Front controller: validate → INSERT tickets/ticket_media → enqueue job_queue
    │  (returns 201 immediately; p95 < 400ms)
    ▼
Worker claims 'redact_media' job
    │  Imagick blur → new file, redacted=1 → enqueue 'classify_ticket'
    ▼
Worker claims 'classify_ticket' job
    │  ImageQualityGate (Haiku) → if unusable: notify reporter, stop
    │  Classifier (Sonnet) → SchemaValidator → INSERT ai_classifications
    │  safety_hazard=1 ? force priority=P1, notify supervisor (parallel human confirm)
    ▼
Worker: DuplicateFinder (SQL filter → trigram/Dice → Haiku for 0.45-0.74 band)
    │  ≥0.75 dice: auto-link duplicate, notify reporter, stop
    │  <0.45 or resolved "different issue": continue
    ▼
Worker: confidence routing (§4.4)
    │  ≥0.75: Routing.php assigns technician, ticket_events 'assigned'
    │  0.50-0.74: assign but flag needs_review, 1hr triage-officer SLA
    │  <0.50: no assignment, human triage queue, ask clarifying_question_th
    ▼
Worker enqueues 'notify_line' → LineClient pushes result to reporter
    │
    ▼
Staff dashboard / triage queue reflects the same row (read path, no duplication of state)
```

Every arrow above is one-directional and DB-mediated — no component calls another component's code directly except through the queue or the DB. This is the correct boundary discipline for a PHP CLI worker architecture (no shared in-process state between the request/response process and the worker process, since they're literally separate PHP processes with separate memory).

### Read paths (dashboard / technician / analytics)

```
Staff dashboard ──GET /tickets, /tickets/{id}──▶ SQL Server (indexed reads, §5.3 indexes)
Technician LIFF ──GET /tickets/{id}, POST .../media, POST .../events──▶ SQL Server + storage/media/
Manager analytics ──GET /analytics/*──▶ SQL Server aggregation queries (never LLM arithmetic)
```

All three are pure read/write against the same tables the worker populates — there is no separate "read model" or cache layer, which is correct at this scale (100,000 tickets target, §9.1) and avoids a consistency-management problem the project doesn't need.

## Scaling Considerations

This is a bounded-scale institutional system, not a consumer product — frame scaling by ticket volume and organizational rollout stage, not user count.

| Stage | Volume | Architecture Adjustments |
|-------|--------|---------------------------|
| Pilot (1-2 buildings) | <50 tickets/day | Single worker process is enough; default `job_queue` polling is fine |
| Full campus rollout | ~500 tickets/day, burst 50/min (SPEC target) | 2 worker processes (already specified, for failover, not throughput); filtered index on `sla_resolve_by` (§5.3) starts to matter for dashboard p95 |
| Multi-campus / multi-division reuse | 1000s tickets/day | `job_queue` polling load becomes worth watching (this is the ceiling of the DB-as-queue pattern, ~100-200 jobs/sec before contention — still far off); consider partitioning `job_queue` by `job_type` or moving to a real broker only if this stage is reached |

### Scaling Priorities

1. **First bottleneck (won't be hit in v1 scope):** Sonnet classification latency (p99 target 60s) — mitigated by the async design itself; if it becomes an issue, the fix is prompt/image-size tuning (already specified: downscale to ≤1568px), not architecture change.
2. **Second, much later:** dashboard query performance at 100k+ tickets — the filtered/composite indexes in §5.3 are the correct first lever; a read replica or archiving old tickets out of the hot table would be the next step, well beyond current scope.

## Anti-Patterns

### Anti-Pattern 1: Calling the LLM synchronously inside the request/webhook handler

**What people do:** Classify or redact inline in `POST /tickets` or the LINE webhook handler because it's simpler to reason about.
**Why it's wrong:** Couples intake availability to Anthropic API availability (violates the hard requirement that intake must never fail because AI is down, §9.2/AC-4) and blows the LINE 2-second webhook ack window and the 400ms API p95 target.
**Do this instead:** Controller only validates, persists, enqueues, returns. All AI work happens in `bin/worker.php`. SPEC.md already enforces this — flag any implementation drift from it as a priority-1 review finding.

### Anti-Pattern 2: Claiming queue rows with SELECT then UPDATE

**What people do:** `SELECT ... WHERE status='pending' LIMIT 1` followed by a separate `UPDATE`.
**Why it's wrong:** Two workers can select the same row before either updates it, causing duplicate processing (double classification, double LINE push, double technician assignment).
**Do this instead:** Single atomic `UPDATE ... OUTPUT ... WHERE status='pending'` as specified in SPEC.md §5.7.

### Anti-Pattern 3: Trusting model-returned identifiers

**What people do:** Persist `building_code`/`asset_code` straight from the model response.
**Why it's wrong:** Hallucinated identifiers silently corrupt location/asset data and defeat the human-triage safety net.
**Do this instead:** Validate every model-returned identifier against the DB before persisting; unmatched → `NULL` + `needs_human_triage` (already specified in §4.3/§12.2/AC-6).

### Anti-Pattern 4: Skipping the quality gate to save a pipeline stage

**What people do:** Send every image straight to the expensive classification call to "simplify the pipeline."
**Why it's wrong:** Wastes the majority of the per-ticket AI budget (§9.4) on unusable images and produces noisy low-confidence output that floods the human triage queue.
**Do this instead:** Keep the Haiku quality gate as a distinct, cheap pre-filter stage — it's the mechanism that keeps the expensive-call rate down and the triage queue's ≤20% target achievable.

### Anti-Pattern 5: Building all 8 components in one undifferentiated phase

**What people do:** Treat "the system" as one deliverable and build breadth-first across intake, AI, dashboard, and mobile UI simultaneously.
**Why it's wrong:** The AI pipeline is the one component gated by an external, non-negotiable quality bar (§11.2: P1 recall ≥95%) that can only be measured against a labelled dataset that doesn't exist yet and depends on an open question to the university (§16.1). Discovering late that the model can't hit that bar, after dashboard/mobile UI are already built against its output shape, is expensive to unwind.
**Do this instead:** Sequence work so the AI pipeline is validated against `bin/eval.php` before the phases that consume its output (dashboard, technician UI) are built out in depth. See Build Order below.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|---------------------|-------|
| Anthropic Claude API | Guzzle REST client (`src/Integration/AnthropicClient.php`), no official PHP SDK | Called only from the worker, never from the request path; must handle timeout/retry/backoff per §12.3 |
| LINE Messaging API | Webhook (inbound) + push API (outbound), signature-verified | Webhook handler only enqueues; `LineClient` used by `NotifyLineHandler` for outbound pushes |
| LINE LIFF | Client-side SDK supplies `userId` to `tech.html` | No server-side integration beyond validating the LIFF-issued token if used for auth |
| Legacy Smart Services | `SmartServicesImporter` — same SQL Server instance (direct join) or Linked Server/CSV if not | One-directional, batch/ETL; not on the live request path |
| University SSO (future) | OIDC/SAML for staff dashboard | Listed as High priority in §15 but not required for MVP demo scope (§14) |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|---------------|-------|
| Front controller ↔ Worker | `job_queue` table only (async, DB-mediated) | No direct function calls or shared process state — they are separate OS processes |
| Worker ↔ `Domain/*` (Routing, Sla, TicketState) | Direct PHP function calls, in-process | Domain layer is pure/deterministic; safe to call synchronously from the worker |
| Worker ↔ `Ai/*` | Direct PHP function calls, in-process, but each stage is a discrete class implementing one pipeline step | Keeps `bin/eval.php` able to call the same `Classifier` class the worker uses, so evaluation and production share one code path |
| Dashboard/LIFF ↔ API | HTTP/JSON only, JWT-authenticated, RBAC-checked | No direct DB access from any frontend; all reads/writes go through `public/index.php` |
| `ticket_events` ↔ everything else | Append-only audit sink, written by controllers/worker on every state transition | `DENY UPDATE, DELETE` at the DB level (§5.6) — this is a boundary enforced by database privilege, not just code convention, which is the correct way to make an audit trail actually tamper-evident |

## Suggested Build Order

This is the most load-bearing output of this document for roadmap phasing. It reorders the 8 components in `<question>` into dependency layers, with parallel tracks marked.

```
Layer 0 — Foundations (blocks everything)
  DB schema/migrations (all §5 tables) · Domain constants (Taxonomy, TicketState, Sla skeleton)
  Db/Connection.php (PDO sqlsrv + deadlock retry) · Env/Logger · ticket_no generator (§5.9)
      │
      ▼
Layer 1 — Core ticket intake (web channel, no AI wired yet)
  POST /tickets (text+image, multipart) · GET /tickets/{id} · idempotency + rate-limit middleware
  Proves: AC-3 (empty input rejected), ticket numbering under concurrency (AC-14)
      │
      ├──────────────────────────────┐
      ▼                              ▼
Layer 2 — Job queue infra       Layer 4a — Routing engine (PARALLEL TRACK, no AI dependency)
  job_queue table + claim SQL     Domain/Routing.php against stub category/skills input
  bin/worker.php polling loop     Unit-tested independently; wired to real data in Layer 5
  bin/scheduler.php stale-lock
  recovery
      │
      ▼
Layer 3 — AI classification pipeline (highest-risk; own dedicated phase(s))
  AnthropicClient → Redactor (Imagick) → ImageQualityGate → Classifier+SchemaValidator
  → DuplicateFinder (SQL filter → trigram/Dice → Haiku adjudication)
  Gate: bin/eval.php against the ≥300-record labelled dataset (§11.1/§11.2) — this is a
  go/no-go checkpoint, not just a nice-to-have test
      │
      ▼
Layer 4b — Wire Routing engine into the real pipeline (merge of Layer 4a + Layer 3 output)
  Confidence-tiered routing (§4.4), safety_hazard hard override, technician assignment
      │
      ├──────────────────────────────┐
      ▼                              ▼
Layer 5 — LINE channel                Layer 6 — Staff triage/dashboard UI
  Webhook receiver can start early    Depends on Layers 1-4b producing real classified,
  (thin: verify signature, enqueue    queued tickets to act on. This is where humans
  same ticket-creation path) but      start correcting/assigning AI output — natural
  full conversational UX (quick       phase immediately after the AI pipeline clears
  replies, duplicate confirm, push    its eval gate.
  notifications) is gated on Layers
  3-4b existing
      │                              │
      └──────────────┬───────────────┘
                      ▼
Layer 7 — Technician LIFF UI
  Needs Layer 4b (assignment) and Layer 6 (someone assigning jobs) to have anything to
  show. After-photo close flow + P1/P2 enforcement (AC-7) belongs here.
                      │
                      ▼
Layer 8 — Analytics/reporting
  Needs meaningful data volume from Layers 1-7 in production, OR the Smart Services
  legacy import to backfill history. Repeat-repair analysis (M-2) is close to worthless
  without either live volume or imported history.
```

### Critical path dependencies (the chain that determines minimum time-to-demo)

```
Schema → Intake API → Job Queue → AI Pipeline (+ eval gate) → Routing wired in → Staff Dashboard → Technician UI
```

Everything else is parallelizable relative to this spine:
- **Routing engine (Layer 4a)** can be built and unit-tested any time after Layer 0, in parallel with Layer 3 — it just can't be *proven end-to-end* until Layer 3 exists.
- **LINE webhook receiver (thin version)** can be stood up in parallel with Layer 2/3 since it's just another caller of the same ticket-creation path — but don't build out the full conversational UX (quick replies, duplicate confirmation, push notifications) until Layers 3-4b are producing real output to talk about.
- **Smart Services import (`SmartServicesImporter`)** is self-contained ETL and can be developed any time — but its *output* is needed for two different downstream purposes on two different timelines: (a) as raw material for the §11.1 labelled eval dataset, which should be assembled **before or during Layer 3**, and (b) as historical backfill for Layer 8 analytics. Recommend starting the data-access conversation with the university (§16 open questions 1-2) immediately, in parallel with Layer 0, since it's off this team's critical path but is the slowest external dependency to unblock.
- **RBAC/JWT auth** is cross-cutting infrastructure needed by Layer 6 and Layer 7 (and optionally Layer 1 for the web channel) — build it once, ideally alongside Layer 1, rather than bolting it onto each UI phase separately.

### Why this order, not component-number order

The `<question>`'s component numbering (1 intake, 2 LINE, 3 queue, 4 AI, 5 routing, 6 dashboard, 7 technician, 8 analytics) is close to correct but understates two things worth calling out explicitly for roadmap phasing:

1. **The AI pipeline (4) is not just "a component" — it's a quality-gated checkpoint.** Nothing downstream (dashboard, technician UI) produces a meaningful demo until Layer 3 clears its eval bar (P1 recall ≥95% is non-negotiable per SPEC.md). Roadmap phases after this point should assume "AI pipeline exists and is validated," not "AI pipeline is still being tuned."
2. **Routing (5) has no AI dependency and should not wait behind the AI phase in the roadmap's critical-path sense** — it's cheap to build and test early, even though its *integration* point is downstream of classification. Treating it as a parallel track avoids it silently becoming a bottleneck late.

## Sources

- SPEC.md §3 (System Architecture), §5.7 (job_queue), §4.4-4.6 (confidence routing, duplicate detection, routing engine), §7 (LINE flow), §11 (model quality) — primary/authoritative source for this project; HIGH confidence.
- Web search corroboration (LOW confidence per this project's `classify-confidence` seam — general web results, not curated/official docs; used only to confirm SPEC.md's choices match established patterns, not to introduce new claims):
  - Database-table job queue patterns vs. Redis (atomic claim, ~100-200 jobs/sec ceiling before contention): "Rethinking the Queue: Is PostgreSQL a Viable Alternative to Redis for Job Processing?" (Medium), "I Removed Redis From My Stack and Used PostgreSQL for Job Queues Instead" (DEV Community)
  - LINE webhook 2-second ack requirement, async desync pattern, `webhookEventId` dedup: LINE Developers docs — "Receive messages (webhook)" and "Messaging API development guidelines" (developers.line.biz)
  - Staged AI pipeline ordering (redact → quality/audit → route) and masking-to-prevent-redetection: AWS "Automatically redact PII in images with Amazon Nova," arXiv "Towards Context-Aware Image Anonymization with Multi-Agent Reasoning"
  - Confidence-tiered human-in-the-loop routing, ~5-10% well-calibrated queue share: Redis "AI Human in the Loop: Production Oversight Patterns," Cordum "Human-in-the-Loop AI: 5 Production Patterns," AllDaysTech "Human-in-the-Loop AI Review Queues"
  - SQL job-queue schema (status/attempts/locked_by/locked_at) and stale-lock/reaper recovery: DEV Community "The Queue Was a Table: How I Built Claim/Unclaim Workers with SKIP LOCKED, Stale Recovery, and Retry Caps"

---
*Architecture research for: AI-assisted maintenance intake/triage system (PHP/SQL Server, async worker, LINE OA)*
*Researched: 2026-08-18*

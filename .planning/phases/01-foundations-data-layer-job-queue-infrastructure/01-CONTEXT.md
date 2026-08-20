# Phase 1: Foundations, Data Layer & Job Queue Infrastructure - Context

**Gathered:** 2026-08-20
**Status:** Ready for planning

<domain>
## Phase Boundary

This phase delivers the data foundation, ticket intake API, and async job-queue infrastructure the rest of the system runs on: DB schema/migrations for all SPEC.md §5 tables, `Db/Connection.php` with deadlock retry, `job_queue` table + atomic claim SQL, `bin/worker.php` polling loop, ticket_no generator, and seeded (or explicitly-placeholder) buildings/assets reference data. No AI, no LINE channel, no dashboard — this phase proves intake + persistence + queueing work correctly and auditably before anything is layered on top.

</domain>

<decisions>
## Implementation Decisions

Gathered in `--auto` mode: every question below was auto-resolved to the recommended option (per `discuss-phase/modes/auto.md`), grounded in SPEC.md where SPEC.md already specifies the answer. Logged inline for audit.

### Migrations
- **D-01:** Forward-only `.sql` migration files run in numeric order by `bin/migrate.php` (per SPEC.md §"repo layout": `db/migrations/001_create_buildings.sql` … `007_create_job_queue.sql`). No production data exists yet, so `_down.sql` rollback scripts are written alongside each `_up.sql` for schema-error recovery during development, but are not exercised as a routine rollback path this phase. — **Reversibility:** reversible — down scripts exist per migration; this is a dev-time convenience decision, not a data-loss risk.
  - `[auto] Migrations — Q: "Forward-only or up/down pairs?" → Selected: "Up/down pairs, down unused in prod this phase" (recommended default, matches SPEC.md repo layout showing buildings_up.sql)`

### Reference Data Seeding
- **D-02:** Phase 1 creates the `buildings` and `assets` schema and ships an empty/minimal seed (schema + a handful of known buildings if available from SPEC.md context, otherwise fully empty) rather than waiting on the university's authoritative building-code list (open question, SPEC.md §16 Q3). The system is built to work correctly with `assets` empty (SPEC.md §5.2 explicit requirement) and to never auto-create a `buildings` row from AI output (SPEC.md pitfall table). Reference-data completeness is tracked as a carried-forward blocker in STATE.md, not a phase-1 blocker. — **Reversibility:** reversible — seed data is additive; more buildings/assets rows can be inserted later without migration.
  - `[auto] Reference data — Q: "Block phase 1 on the university's building list, or ship an empty/placeholder seed?" → Selected: "Ship empty/placeholder seed, track completeness separately" (recommended default — SPEC.md explicitly designs for buildings/assets being incomplete or empty)`

### Deployment Platform Target
- **D-03:** Phase 1's worker-supervision script is written for Linux/systemd first (SPEC.md §"Deployment" shows both a systemd unit and a Windows Task Scheduler path; Linux/Nginx/PHP-FPM is the lower-friction default for a PHP CLI worker). The Windows/IIS path remains an open question pending university IT confirmation (STATE.md carried blocker) — `bin/worker.php` itself is platform-agnostic PHP, so this decision only affects which supervisor config ships first; the other can be added without touching application code. — **Reversibility:** reversible — supervisor config is a thin wrapper; PHP application code is unaffected by platform choice.
  - `[auto] Deployment platform — Q: "Which platform's supervisor config to write first?" → Selected: "Linux/systemd first, Windows/Task Scheduler deferred" (recommended default per SPEC.md's own ordering and lower operational friction; university's actual platform is still an open question)`

### Job Queue Worker Concurrency
- **D-04:** Phase 1 ships a single always-restarting worker process (one systemd unit, not the `@service` templated multi-instance form SPEC.md sketches). The atomic `UPDATE ... WITH (ROWLOCK, READPAST, UPDLOCK)` claim pattern already makes concurrent workers safe, so scaling to multiple worker instances later is a config change, not a code change — single-worker is sufficient for the stated volume (500 tickets/day) and simpler to operate/debug during this foundational phase. — **Reversibility:** reversible — claim SQL already supports N workers; scaling out is a deployment change only.
  - `[auto] Worker concurrency — Q: "Ship single worker instance or templated multi-worker from day one?" → Selected: "Single worker instance, multi-worker deferred" (recommended default — claim pattern already race-safe, no code change needed to scale later, and 500 tickets/day doesn't need it yet)`

### Claude's Discretion
- Exact `.sql` migration file naming/numbering beyond what SPEC.md's repo-layout sketch shows (SPEC.md lists 001–007+; planner may add further migrations as schema needs dictate, e.g. `ticket_events` indexes).
- Internal structure of `bin/migrate.php` (transaction-per-file vs transaction-per-batch) — implementation detail, no user-facing behavior difference.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Authoritative spec
- `SPEC.md` (repo root, v2.0, 2026-08-18) §5 (data model), §5.7 (`job_queue` schema + atomic claim SQL), §5.9 (ticket_no generation, race-safety requirement), §"repo layout" (migration file structure, worker/scheduler layout), §"Deployment" (systemd unit + Task Scheduler configs), §16 Q3/Q7 (open questions: building-code source, deployment platform) — the full technical spec this phase implements against.

### Project-level
- `.planning/PROJECT.md` — core value, constraints (no Redis/Elasticsearch/vector DB, SQL Server `job_queue` in place of a message queue), audit-trail requirement
- `.planning/REQUIREMENTS.md` — AUDIT-01 (append-only `ticket_events`) is this phase's mapped v1 requirement per Traceability section
- `.planning/research/SUMMARY.md` §"Recommended Stack", §"Critical Pitfalls" (Pitfall 4: hallucination guard depends on incomplete reference data; Pitfall 6: idempotent job handlers) — research findings that directly informed D-02 and the queue design
- `.planning/research/STACK.md` — version-pin findings: `pdo_sqlsrv` 5.13.0 requires PHP 8.3+ (conflicts with SPEC.md's stated 8.2+ floor — carried blocker, confirm with university IT before `Db/Connection.php` is written)
- `.planning/research/PITFALLS.md` — Pitfall 5 (non-idempotent job handlers cause double AI billing / duplicate notifications on crash-retry) — planner should design the claim+idempotency-check pattern together, not defer idempotency to a later phase
- `.planning/ROADMAP.md` Phase 1 section — success criteria this phase must satisfy

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- None — greenfield project, no application code exists yet (repo currently contains only `.planning/`, `.claude/`, and `SPEC.md`).

### Established Patterns
- None yet — this phase establishes the foundational patterns (DB connection/retry, migration runner, job-queue claim) that every later phase builds on.

### Integration Points
- `db/migrations/*.sql` — schema this phase creates; Phase 2 (AI pipeline) and Phase 3 (routing) both read/write these tables directly.
- `job_queue` table + `bin/worker.php` — the queueing mechanism Phase 2's AI pipeline stages will enqueue into and consume from; the idempotency-check decorator SPEC.md/PITFALLS.md call for should live here so every future handler inherits it for free.

</code_context>

<specifics>
## Specific Ideas

No specific ideas beyond what SPEC.md already specifies — this phase is fully spec-driven (auto mode, no interactive discussion). SPEC.md's existing repo-layout sketch (§"repo layout") should be followed as-is for migration file naming and directory structure.

</specifics>

<deferred>
## Deferred Ideas

- Multi-worker horizontal scaling (D-04) — deferred until volume requires it; claim SQL already supports it without code changes.
- Windows/IIS deployment path (D-03) — deferred until university confirms platform; write once confirmed, no application-code impact.
- Full buildings/assets reference data (D-02) — deferred pending university's authoritative building-code list (SPEC.md §16 Q3); tracked as a carried-forward blocker in STATE.md, not this phase's responsibility to resolve.

### Reviewed Todos (not folded)
None — no pending todos matched this phase (`todo.match-phase` returned 0 matches).

</deferred>

---

*Phase: 1-Foundations, Data Layer & Job Queue Infrastructure*
*Context gathered: 2026-08-20*

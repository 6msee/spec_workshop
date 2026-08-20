# Phase 1: Foundations, Data Layer & Job Queue Infrastructure - Research

**Researched:** 2026-08-20
**Domain:** PHP 8.2+/SQL Server data layer, migration tooling, ticket-intake persistence, and a SQL-Server-as-queue async worker (no AI, no LINE, no dashboard yet)
**Confidence:** MEDIUM-HIGH (schema/queue/migration design is directly specified by an authoritative pre-existing SPEC.md — HIGH; exact driver-version and locking-pattern details are WebSearch-cross-checked against Microsoft Learn / PECL / community sources — MEDIUM)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

- **D-01 (Migrations):** Forward-only `.sql` migration files run in numeric order by `bin/migrate.php` (per SPEC.md §"repo layout": `database/migrations/001_create_buildings.sql` … `008_create_support_tables.sql`). No production data exists yet, so `_down.sql` rollback scripts are written alongside each `_up.sql` for schema-error recovery during development, but are not exercised as a routine rollback path this phase. Reversible — down scripts exist per migration; dev-time convenience, not a data-loss risk.
- **D-02 (Reference Data Seeding):** Phase 1 creates the `buildings` and `assets` schema and ships an empty/minimal seed (schema + a handful of known buildings if available, otherwise fully empty) rather than waiting on the university's authoritative building-code list (SPEC.md §16 Q3, open). The system is built to work correctly with `assets` empty (SPEC.md §5.2) and to never auto-create a `buildings` row from AI output (SPEC.md pitfall table). Reference-data completeness is tracked as a carried-forward blocker in STATE.md, not a phase-1 blocker. Reversible — seed data is additive.
- **D-03 (Deployment Platform Target):** Phase 1's worker-supervision script is written for Linux/systemd first (SPEC.md §"Deployment"). Windows/IIS path remains an open question pending university IT confirmation (STATE.md carried blocker). `bin/worker.php` itself is platform-agnostic PHP; this decision only affects which supervisor config ships first. Reversible.
- **D-04 (Job Queue Worker Concurrency):** Phase 1 ships a single always-restarting worker process (one systemd unit, not the `@service` templated multi-instance form). The atomic `UPDATE ... WITH (ROWLOCK, READPAST, UPDLOCK)` claim pattern already makes concurrent workers safe; scaling to multiple worker instances later is a config change, not a code change. Reversible.

### Claude's Discretion

- Exact `.sql` migration file naming/numbering beyond what SPEC.md's repo-layout sketch shows (SPEC.md lists 001–007+; planner may add further migrations as schema needs dictate, e.g. `ticket_events` indexes).
- Internal structure of `bin/migrate.php` (transaction-per-file vs transaction-per-batch) — implementation detail, no user-facing behavior difference.

### Deferred Ideas (OUT OF SCOPE)

- Multi-worker horizontal scaling (D-04) — deferred until volume requires it; claim SQL already supports it without code changes.
- Windows/IIS deployment path (D-03) — deferred until university confirms platform; write once confirmed, no application-code impact.
- Full buildings/assets reference data (D-02) — deferred pending university's authoritative building-code list (SPEC.md §16 Q3); tracked as a carried-forward blocker in STATE.md.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|-------------------|
| AUDIT-01 | Full audit trail (`ticket_events`, append-only) for every state transition and reclassification | §5.6 schema verified from SPEC.md (verbatim columns below); `DENY UPDATE, DELETE` enforcement pattern confirmed as the correct non-Ledger SQL Server idiom (see Architecture Patterns, Pattern 3); Code Examples section gives the exact grant statement and an event-writer helper pattern so every future phase's writes go through one audited path. |

</phase_requirements>

## Summary

This phase builds nothing user-facing beyond an internal `POST /tickets`-style intake endpoint — its job is to make every later phase (AI pipeline, routing, LINE/dashboard) sit on a data layer that cannot silently corrupt, double-process, or lose an audit record. Three things dominate the technical risk here, all confirmed by this research pass: (1) the `pdo_sqlsrv` driver's PHP-version floor is a **real, current, blocking conflict** with SPEC.md's stated "PHP 8.2+" — the newest driver (5.13.3, released 2026-08-07) requires PHP 8.3+ and the last driver release supporting PHP 8.2 is 5.12.0; this must be resolved with university IT before `Db/Connection.php` is written, and the plan must branch on the answer. (2) SQL Server's `job_queue` atomic-claim pattern (`UPDATE TOP(1) ... WITH (ROWLOCK, READPAST, UPDLOCK) ... OUTPUT`) is a well-established, correctly-specified idiom in SPEC.md §5.7 — this research confirms it against independent SQL Server locking sources and adds the one thing SPEC.md's SQL alone doesn't show: the PDO/`pdo_sqlsrv` execution shape (prepared statement + `fetchAll()` against the `OUTPUT` rowset, not a separate `SELECT`). (3) `ticket_events` immutability (this phase's only mapped requirement, AUDIT-01) is enforced by `DENY UPDATE, DELETE` at the database-object level, granted to the application's SQL login — this is the correct pattern for non-Ledger SQL Server (SQL Server 2019+ per SPEC.md, Ledger tables are a 2022+ feature not in scope) and this research adds the exact `GRANT`/`DENY` sequence and a verification query the plan should include.

A fourth, lower-drama but still load-bearing finding: this phase's job-queue infrastructure has **no real AI handler to run yet** (Phase 2 is a dedicated, gated AI phase per CONTEXT.md/ROADMAP.md). `bin/worker.php`'s atomic-claim loop must be provably correct (success criterion 3: "no double-processing on concurrent workers or crash-retry") using a stub/no-op handler — the plan should design the claim+dispatch loop and a trivial `Handlers/NoopHandler.php` (or similar) that only proves the claim mechanics, leaving `ClassifyTicketHandler` and friends for Phase 2. Idempotency-check scaffolding (a handler base class or decorator, per PITFALLS.md Pitfall 6) belongs here structurally even though there's no AI handler yet to exercise it fully — every future handler should inherit it for free.

**Primary recommendation:** Confirm the PHP 8.2-vs-8.3 / `pdo_sqlsrv` version floor with university IT before writing `Db/Connection.php` (blocks on an external answer — plan both branches: PHP 8.3+ with `pdo_sqlsrv` 5.13.x, or PHP 8.2 pinned to `pdo_sqlsrv` 5.12.0). Build all eight SPEC.md §5 tables via forward `.sql` migrations with paired `_down.sql` files, `DENY UPDATE, DELETE` on `ticket_events` applied in the same migration that creates it, the `ticket_counters` UPDLOCK/HOLDLOCK pattern for `ticket_no` generation, and a single-worker `bin/worker.php` using the exact `UPDATE...OUTPUT` claim SQL against a stub handler to prove atomicity — leaving real AI processing for Phase 2.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|--------------|----------------|-----------|
| Ticket intake (persist ticket + media rows, return `ticket_no`) | API / Backend (`public/index.php` → `TicketController`) | Database (constraints, `ticket_no` uniqueness) | Front controller validates and persists synchronously; DB enforces uniqueness/CHECK constraints as the final guard, never trust app-layer validation alone. |
| `ticket_no` generation (race-safe counter) | Database (`ticket_counters` + `UPDLOCK, HOLDLOCK`) | API (wraps the call in a transaction) | Race-safety under concurrent `POST /tickets` (AC-14) can only be guaranteed inside the DB transaction boundary — PHP-side locking cannot coordinate across separate request processes. |
| Audit trail (`ticket_events`, append-only) | Database (`DENY UPDATE, DELETE` grant) | API (writes one row per transition, never mutates) | Tamper-evidence must be enforced by database privilege, not application convention — this is the whole point of AUDIT-01; a compromised or buggy app-layer write path must still be unable to edit history. |
| Job enqueue (write to `job_queue`) | API / Backend (controller, end of request) | Database (row insert) | Front controller enqueues as the last step before returning — never blocks on AI/worker completion. |
| Job claim + dispatch loop | Backend / CLI Worker (`bin/worker.php`, separate OS process) | Database (atomic `UPDATE...OUTPUT`) | Claiming safety is a DB-transaction property; the worker process is a thin poll-claim-dispatch loop with no shared state with the API process. |
| Stale-lock recovery | Backend / CLI Scheduler (`bin/scheduler.php`, cron-driven) | Database (`UPDATE job_queue SET status='pending' WHERE status='running' AND locked_at < ...`) | Crash recovery is a periodic sweep, structurally separate from the always-running worker loop. |
| Buildings/assets reference data | Database (seed via migration/seed script) | — | Static reference data with no business logic attached this phase — pure schema + seed rows. |
| File/media storage (raw upload persistence) | API / Backend (`storage/media/` filesystem, outside webroot) | Database (`ticket_media` row pointing at path) | SPEC.md §10.2 requires files outside the webroot; DB row is metadata only, actual bytes live on disk this phase (redaction/processing is Phase 2). |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|---------------|
| PHP | 8.2 **or** 8.3 — floor depends on unresolved IT answer (see Pitfall below) | API + CLI worker runtime | Locked by SPEC.md/project; the exact floor gates which `pdo_sqlsrv` release is installable. `[CITED: SPEC.md §8.1]` |
| `pdo_sqlsrv` (Microsoft Drivers for PHP for SQL Server) | **5.13.3** (released 2026-08-07) if PHP 8.3+; **5.12.0** if PHP 8.2 only | SQL Server connectivity from PHP with prepared statements + `NVARCHAR`/Unicode support | Only Microsoft-supported driver path to SQL Server from PHP. `[VERIFIED: PECL/Packagist, WebSearch cross-checked against Microsoft Community Hub and pecl.php.net 2026-08-20]` — 5.13.3 requires PHP 8.3+ and does **not** install on PHP 8.2; 5.12.0 (Feb 2024) was built against PHP 8.1/8.2/8.3 and is the last release with an 8.2 build. |
| Microsoft ODBC Driver for SQL Server | 18 (17 also supported) | Transport layer `pdo_sqlsrv` sits on | System-level dependency (not Composer), must be installed by IT before the extension will connect. `[CITED: STACK.md, project research]` |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `vlucas/phpdotenv` | `^5.6` (latest `v5.6.4`, released 2026-07-06) | `.env` loading | Every environment; standard for this stack, no better alternative. `[VERIFIED: Packagist p2 API, fetched 2026-08-20]` |
| `ramsey/uuid` | `^4.7` (latest `4.9.3`, released 2026-06-18) | Generate `UNIQUEIDENTIFIER`-compatible GUIDs in PHP where `NEWSEQUENTIALID()` isn't used (media filenames, idempotency keys) | Filename regeneration (§10.2 pattern, applies to media storage this phase). `[VERIFIED: Packagist p2 API, fetched 2026-08-20]` |
| `monolog/monolog` | `^3.10` (latest `3.10.0`, released 2026-01-02) | Structured JSON logging (`storage/logs/`) | From day one — `Db/Connection.php`, `bin/migrate.php`, `bin/worker.php` all need structured logs for debugging deadlock retries and claim behavior. `[VERIFIED: Packagist p2 API, fetched 2026-08-20]` |
| `phpunit/phpunit` (dev) | `^11.0` — **not** `^13.0` | Test runner for `Db/Connection.php`, migration runner, and job-claim concurrency tests | Latest is `13.3.1` (2026-08-13) but PHPUnit 13 requires PHP 8.4+, incompatible with this project's 8.2/8.3 floor either way. `[VERIFIED: Packagist p2 API, fetched 2026-08-20 — version confirmed; PHP-8.4-only requirement CITED from STACK.md/prior project research]` |

**Not needed this phase (deferred, do not install yet):** `guzzlehttp/guzzle`, `firebase/php-jwt`, `opis/json-schema`, `linecorp/line-bot-sdk`, `ext-imagick` — these belong to the AI pipeline (Phase 2) and multi-channel launch (Phase 4). Installing them now adds unused attack surface and dependency-update burden for no phase-1 benefit. `ext-fileinfo` and `ext-mbstring` (both PHP core/bundled extensions, not Composer packages) **are** needed this phase for MIME-sniffing uploaded files and Thai-safe string handling respectively.

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `pdo_sqlsrv` 5.13.x (PHP 8.3+) | `pdo_sqlsrv` 5.12.0 (PHP 8.2) | Only if university IT cannot provide PHP 8.3+; 5.12.0 is functionally adequate for this phase's needs (prepared statements, transactions, `OUTPUT` clause support) but is no longer receiving new-feature updates — track a driver-upgrade task for whenever the PHP floor moves. |
| `DENY UPDATE, DELETE` grant on `ticket_events` | SQL Server 2022+ Ledger append-only tables | Ledger gives engine-level tamper-evidence with cryptographic verification, but requires SQL Server 2022+ (SPEC.md states "2019+") and changes table DDL/query behavior significantly. Not recommended this phase — `DENY` is what SPEC.md specifies and is sufficient for AUDIT-01 as written. |
| Clustered PK on `UNIQUEIDENTIFIER DEFAULT NEWSEQUENTIALID()` (SPEC.md's design) | Narrow `BIGINT IDENTITY` clustered key + nonclustered unique constraint on a `UNIQUEIDENTIFIER` | The narrow-clustered-key pattern is the more commonly cited "best practice" for minimizing fragmentation and shrinking every nonclustered index's leaf-row pointer, but SPEC.md has already locked `UNIQUEIDENTIFIER`/`NEWSEQUENTIALID()` project-wide (§5.0) for cross-system-identity reasons; `NEWSEQUENTIALID()` (vs. `NEWID()`) already captures most of the fragmentation benefit. Do not deviate from SPEC.md's schema without a discuss-phase decision — flag as an option only if dashboard-scale performance work in a later phase reveals a real problem. |

**Installation:**
```bash
composer require vlucas/phpdotenv:^5.6 ramsey/uuid:^4.7 monolog/monolog:^3.10
composer require --dev phpunit/phpunit:^11.0

# System-level (NOT Composer — confirm with university IT before development starts):
# - PHP 8.2 or 8.3 (resolve which; see Pitfall 1)
# - Microsoft ODBC Driver 18 (or 17) for SQL Server
# - php-pdo_sqlsrv matching the resolved PHP version (5.12.0 for 8.2, 5.13.3 for 8.3+)
```

**Version verification performed this session:** `vlucas/phpdotenv`, `ramsey/uuid`, `monolog/monolog`, `phpunit/phpunit` versions and publish dates confirmed live against Packagist's `p2` API (`repo.packagist.org/p2/<vendor>/<package>.json`) on 2026-08-20 — see exact output in Sources. `pdo_sqlsrv` version/PHP-floor confirmed via WebSearch cross-referencing PECL, Microsoft Community Hub, and GitHub `microsoft/msphpsql` releases, same date.

## Package Legitimacy Audit

> All Composer packages recommended this phase are long-established, widely-used libraries (Monolog: Seldaek/monolog, 20k+ GitHub stars-class project; `ramsey/uuid` and `vlucas/phpdotenv`: multi-year, multi-billion-download-class PHP ecosystem staples) already referenced by name in the project's own prior research (STACK.md) and SPEC.md's `composer.json` sketch — none were freshly "discovered" via an unverified web search this session, and none show any slopsquat risk pattern (no near-miss typosquat names considered, no sub-1-year-old packages recommended).
>
> **Tooling note:** the `package-legitimacy check` seam (`gsd-tools query package-legitimacy check`) only supports `--ecosystem npm|pypi|crates` and rejected `composer` as an ecosystem argument this session. In its absence, legitimacy was verified manually: (1) direct query of Packagist's `p2` API for current version + publish date (registry-authoritative, not WebSearch-derived), (2) cross-reference against SPEC.md's own `composer.json` (§8.2) and the project's prior STACK.md research, which independently named the same packages. This is a weaker signal than the automated `[OK]`/`[SUS]`/`[SLOP]` seam verdict — flag to the planner that no `checkpoint:human-verify` gate is strictly required for these packages given their maturity, but the planner may add one if the team wants belt-and-suspenders given the seam couldn't run.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|--------------|---------|-------------|
| `vlucas/phpdotenv` | Packagist | 10+ years (v1 circa 2013) | Billions (bundled by Laravel, Symfony ecosystems) | `github.com/vlucas/phpdotenv` | Manually verified OK | Approved |
| `ramsey/uuid` | Packagist | 10+ years | Hundreds of millions | `github.com/ramsey/uuid` | Manually verified OK | Approved |
| `monolog/monolog` | Packagist | 15+ years | Billions (default logger for Laravel, Symfony) | `github.com/Seldaek/monolog` | Manually verified OK | Approved |
| `phpunit/phpunit` | Packagist | 20+ years | Billions | `github.com/sebastianbergmann/phpunit` | Manually verified OK | Approved |
| `microsoft/pdo_sqlsrv` (PECL, not Composer) | PECL / GitHub | 15+ years (`msphpsql` project) | Official Microsoft driver | `github.com/microsoft/msphpsql` | Manually verified OK | Approved — install version per resolved PHP floor |

**Packages removed due to `[SLOP]` verdict:** none.
**Packages flagged as suspicious `[SUS]`:** none.

## Architecture Patterns

### System Architecture Diagram

```
POST /tickets (multipart: text + images)
        │
        ▼
┌───────────────────────────────────────────────┐
│ Front controller (public/index.php)            │
│  1. Validate input (text or images present)    │
│  2. finfo_file() MIME check on each upload      │
│  3. BEGIN TRAN                                  │
│     a. Claim next ticket_no (UPDLOCK/HOLDLOCK)  │
│     b. INSERT tickets row                       │
│     c. INSERT ticket_media row(s)                │
│     d. INSERT ticket_events (event_type=created) │
│     e. INSERT job_queue (job_type=redact_media   │
│        or classify_ticket — stub payload only,   │
│        no real handler processes it yet)         │
│  4. COMMIT TRAN                                  │
│  5. Return 201 { ticket_no, id, status }         │
└───────────────────┬─────────────────────────────┘
                    │ writes only — never blocks on AI
                    ▼
        ┌───────────────────────┐
        │   SQL Server            │
        │  tickets · ticket_media │
        │  ticket_events (append) │
        │  job_queue · ticket_    │
        │  counters · buildings/  │
        │  assets (seeded/empty)  │
        └───────────┬─────────────┘
                    │ polling claim
                    ▼
┌───────────────────────────────────────────────┐
│ bin/worker.php (single systemd-supervised loop) │
│  LOOP every WORKER_POLL_SECONDS:                │
│   1. UPDATE TOP(1) job_queue                    │
│      WITH (ROWLOCK, READPAST, UPDLOCK)          │
│      SET status='running', locked_by=@id, ...   │
│      OUTPUT inserted.*                          │
│      WHERE status='pending' AND run_after<=now   │
│   2. If claimed: dispatch to Handlers\NoopHandler│
│      (Phase 1 stub — proves atomicity only;      │
│       real AI handlers land in Phase 2)          │
│   3. Mark job status='done' or 'failed'          │
│   4. Exit after N jobs / T minutes → systemd      │
│      Restart=always respawns                      │
└───────────────────────────────────────────────┘
                    │
                    ▼ (separate cron-driven process)
┌───────────────────────────────────────────────┐
│ bin/scheduler.php (cron, every minute)          │
│  Reset job_queue rows stuck in 'running' with    │
│  locked_at older than WORKER_STALE_LOCK_MINUTES  │
│  back to 'pending' (crash recovery)               │
└───────────────────────────────────────────────┘
```

A reader can trace the primary use case (a ticket enters via the intake API, is persisted, and a job is safely claimed by exactly one worker) end-to-end through the arrows above without ever crossing into AI/LINE/dashboard territory — those are explicitly out of scope this phase.

### Recommended Project Structure

Per SPEC.md §8.4 (§"repo layout" — this phase builds the subset needed for intake + queue infra, not the full tree):

```
up-fix/
├── composer.json
├── .env.example
├── public/
│   └── index.php                       # front controller: ticket intake only this phase
├── src/
│   ├── Http/
│   │   ├── Router.php
│   │   ├── Request.php / Response.php
│   │   └── Controllers/TicketController.php
│   ├── Domain/
│   │   └── Taxonomy.php                # §4.1 constants — needed for tickets.category CHECK values
│   ├── Queue/
│   │   ├── JobQueue.php                # enqueue() + claim(), §5.7
│   │   └── Handlers/
│   │       └── NoopHandler.php         # Phase 1 stub — proves claim/dispatch loop only
│   ├── Support/
│   │   ├── Env.php
│   │   └── Logger.php
│   └── Db/
│       └── Connection.php              # PDO sqlsrv + deadlock (1205) retry — this phase's riskiest file
├── bin/
│   ├── worker.php                      # job_queue consumer loop
│   ├── scheduler.php                   # stale-lock recovery only this phase
│   └── migrate.php                     # runs database/migrations/*.sql in order
├── database/
│   ├── migrations/
│   │   ├── 001_create_buildings_up.sql / 001_create_buildings_down.sql
│   │   ├── 002_create_assets_up.sql / 002_create_assets_down.sql
│   │   ├── 003_create_tickets_up.sql / 003_create_tickets_down.sql
│   │   ├── 004_create_ticket_media_up.sql / 004_create_ticket_media_down.sql
│   │   ├── 005_create_ai_classifications_up.sql / 005_create_ai_classifications_down.sql   (schema only — no writer this phase)
│   │   ├── 006_create_ticket_events_up.sql / 006_create_ticket_events_down.sql             (includes the DENY grant)
│   │   ├── 007_create_job_queue_up.sql / 007_create_job_queue_down.sql
│   │   └── 008_create_support_tables_up.sql / 008_create_support_tables_down.sql           (ticket_counters, holidays, rate_limits, idempotency_keys)
│   └── seed/
│       └── buildings_up.sql            # empty or placeholder per D-02
├── storage/
│   ├── media/                          # raw upload storage this phase — no redaction pipeline yet
│   └── logs/
└── tests/
    └── Unit/ (Db/Connection retry test, JobQueue claim-concurrency test, ticket_no race test)
```

**Note on migration file naming:** SPEC.md's own repo-layout sketch (§8.4) shows both `buildings_up.sql` (no numeric prefix, under `database/seed/`) and, separately, `001_create_buildings.sql` under `database/migrations/`. This research treats the numbered-migration-file convention as the one to follow for schema DDL (matches D-01's explicit numbering) and reserves the unprefixed `_up.sql` naming for the `database/seed/` directory only, consistent with SPEC.md's own two different naming conventions in two different directories. Confirm this reading in planning — it is `[ASSUMED]`, not verified against a second SPEC.md example.

### Pattern 1: Atomic job-queue claim (never `SELECT` then `UPDATE`)

**What:** A single atomic `UPDATE ... OUTPUT ... WHERE` statement claims and returns a row in one round trip — no separate `SELECT` exists for another worker to race against.
**When to use:** Every job claim in `bin/worker.php`, with no exceptions.
**Example (verbatim from SPEC.md, this session's `Read`):**
```sql
-- Source: SPEC.md:420-429 (§5.7)
UPDATE TOP (1) job_queue WITH (ROWLOCK, READPAST, UPDLOCK)
SET status = 'running',
    locked_by = @workerId,
    locked_at = SYSUTCDATETIME(),
    attempts = attempts + 1,
    updated_at = SYSUTCDATETIME()
OUTPUT inserted.id, inserted.job_type, inserted.payload, inserted.attempts
WHERE status = 'pending'
  AND run_after <= SYSUTCDATETIME();
```
**PDO/`pdo_sqlsrv` execution shape** (not shown in SPEC.md — this research's addition, `[CITED: MSSQLTips "Processing Data Queues in SQL Server with READPAST and UPDLOCK", Erik Darling "Building Reusable Queues" — cross-checked against SPEC.md's SQL]`):
```php
// Source: pattern derived from SPEC.md:420-429 SQL + standard pdo_sqlsrv prepared-statement usage
$stmt = $pdo->prepare($claimSql);
$stmt->bindValue(':workerId', $workerId, PDO::PARAM_STR);
$stmt->execute();
$claimed = $stmt->fetchAll(PDO::FETCH_ASSOC); // 0 or 1 row from the OUTPUT clause
if (empty($claimed)) {
    // nothing pending — sleep WORKER_POLL_SECONDS and retry
}
```
The `OUTPUT` clause's result set is returned exactly like a `SELECT`'s would be to `pdo_sqlsrv` — no special driver mode is needed, but the statement genuinely is an `UPDATE`, so `PDO::lastInsertId()` semantics do not apply and the plan should use `fetchAll()`/`fetch()` against the executed `UPDATE` statement directly.

### Pattern 2: SQL Server deadlock (error 1205) retry

**What:** Catch the deadlock-victim error and retry the whole transaction a bounded number of times.
**When to use:** Every write transaction in `Db/Connection.php` that could contend with concurrent writers — most directly, the `ticket_counters` UPDLOCK/HOLDLOCK block and any `job_queue` claim under load.
**Detection detail confirmed this session** `[CITED: Microsoft Learn MSSQLSERVER_1205, WebSearch cross-checked]`: SQL Server raises error number **1205** with SQLSTATE **40001** when a transaction is chosen as the deadlock victim; this maps to a `PDOException` whose `errorInfo[1]` is `1205` when caught via `pdo_sqlsrv`.
```php
// Source: pattern synthesized from SPEC.md:1006 ("Catch error 1205 ... retry up to 3×")
// + Microsoft Learn MSSQLSERVER_1205 semantics — code shape is this research's own composition, [ASSUMED] on exact array indices, verify against the actual pdo_sqlsrv errorInfo shape during implementation
function withDeadlockRetry(callable $fn, int $maxAttempts = 3)
{
    $attempt = 0;
    while (true) {
        try {
            return $fn();
        } catch (PDOException $e) {
            $sqlServerCode = $e->errorInfo[1] ?? null;
            $attempt++;
            if ($sqlServerCode !== 1205 || $attempt >= $maxAttempts) {
                throw $e;
            }
            usleep(random_int(50_000, 200_000)); // jittered backoff before retry
        }
    }
}
```
**Warning:** `errorInfo` array shape for `pdo_sqlsrv` should be empirically confirmed (`var_dump($e->errorInfo)`) against the actual installed driver/ODBC version during implementation — driver error-reporting shape has changed across major ODBC driver versions in other ecosystems and should not be assumed identical to `pdo_mysql`'s.

### Pattern 3: Immutable audit table via `DENY`

**What:** Grant the application's SQL login `INSERT` (implicitly, via normal table permissions) but explicitly `DENY UPDATE, DELETE` on `ticket_events`, at the object level, in the same migration that creates the table.
**When to use:** `ticket_events` only, this phase — this is the direct implementation of AUDIT-01.
**Example (verbatim from SPEC.md, this session's `Read`):**
```sql
-- Source: SPEC.md:399 (§5.6, quoted verbatim)
-- "Grant DENY UPDATE, DELETE ON ticket_events to the application database user."
```
**Corrected T-SQL syntax** (SPEC.md's prose line above is not valid T-SQL as literally written — `DENY` is a statement, not a clause of `GRANT`; this is this research's correction, `[CITED: Microsoft Learn "DENY (Transact-SQL)"]`):
```sql
-- Source: standard DENY syntax per Microsoft Learn, applied to SPEC.md's stated intent
DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app;
```
Run this as the final statement of the `ticket_events`-creating migration (`006_create_ticket_events_up.sql`), not as a separate manual step — otherwise a fresh environment created by `bin/migrate.php` alone would be missing the guarantee AUDIT-01 depends on. Add a verification query to the plan's checkpoint: attempt an `UPDATE`/`DELETE` against `ticket_events` as the app login and confirm it is rejected (this is directly testable and should be an automated check, not just eyeballed).

### Pattern 4: Race-safe ticket number generation

**What:** A counter table with `UPDLOCK, HOLDLOCK` inside an explicit transaction, never `MAX(ticket_no) + 1`.
**When to use:** Every `POST /tickets` call — this is what makes AC-14 (50 concurrent requests, all `ticket_no` values distinct) pass.
**Example (verbatim from SPEC.md, this session's `Read`):**
```sql
-- Source: SPEC.md:458-465 (§5.9)
BEGIN TRAN;
  UPDATE ticket_counters WITH (UPDLOCK, HOLDLOCK)
  SET last_no = last_no + 1
  OUTPUT inserted.last_no
  WHERE period = @period;   -- '202608'
  -- if no row exists, INSERT starting at 1
COMMIT;
```
**Note the row-may-not-exist branch is not spelled out in SQL** by SPEC.md — the plan needs an explicit `IF @@ROWCOUNT = 0` fallback to `INSERT` a fresh `period` row starting at 1, guarded by the same transaction, or a `MERGE`-based upsert. This is a real implementation gap in the SPEC.md snippet worth flagging to the planner, not something to silently paper over.

### Anti-Patterns to Avoid

- **Claiming with `SELECT` then `UPDATE`:** Two workers can select the same pending row before either updates it — always use the single atomic `UPDATE...OUTPUT` statement (Pattern 1). `[CITED: SPEC.md §5.7, ARCHITECTURE.md Anti-Pattern 2]`
- **Trusting `$_FILES['type']` for uploaded MIME type:** SPEC.md §6.1 explicitly requires `finfo_file()` detection — the client-supplied MIME string is attacker-controlled and must never be used to decide storage/serving behavior. `[VERIFIED: SPEC.md:497]` — *"Detect file type with `finfo_file()`. **Never trust** the client-supplied `$_FILES['type']`."*
- **Building a `redact_media`/`classify_ticket` handler this phase:** Those handlers require Imagick and the Anthropic client respectively, both explicitly out of scope until Phase 2. Enqueue the job types (so the schema and `job_queue` writes are exercised end-to-end), but dispatch them to a no-op stub so success criterion 3 (atomic claim, no double-processing) can be proven without pulling forward AI-pipeline work.
- **Skipping the `_down.sql` migration for a table just because "it's early":** D-01 requires every migration to ship with a paired down script for dev-time schema-error recovery, even though they won't be exercised as a production rollback path this phase.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|--------------|-----|
| Concurrent job claiming | A custom PHP-level lock/mutex around `job_queue` polling | The single atomic SQL `UPDATE...OUTPUT` statement (Pattern 1) | Any PHP-side coordination between separate OS processes requires its own distributed-lock mechanism (file locks don't span hosts, and this project explicitly has no Redis) — the DB transaction is the only coordination point that's already guaranteed consistent. |
| UUID/GUID generation for filenames/idempotency keys | Custom random-string generator | `ramsey/uuid` | Collision-resistance and RFC-4122 compliance are exactly the kind of "looks easy, has edge cases" problem (clock-based UUID variants, byte-ordering) not worth re-deriving. |
| `.env` parsing | Custom `parse_ini_file()`-based loader | `vlucas/phpdotenv` | Handles quoting, multiline values, variable interpolation, and `.env.example` diffing conventions the team will otherwise reinvent badly. |
| Deadlock retry backoff | A fixed-delay retry loop | Jittered exponential backoff (Pattern 2) | Fixed-delay retries under contention synchronize retries across concurrent transactions and can produce repeated deadlock storms; jitter is the standard, cheap fix. |
| Ticket number sequencing | `SELECT MAX(ticket_no)` then increment in PHP | The `ticket_counters` `UPDLOCK, HOLDLOCK` pattern (Pattern 4) | `MAX()+1` races under concurrent inserts — SPEC.md itself flags this explicitly (§5.9: *"Never use `MAX(ticket_no) + 1` — it races under concurrent submissions."*) `[VERIFIED: SPEC.md:468]` |

**Key insight:** Every "don't hand-roll" item above traces back to the same root cause — this phase's entire job is concurrency correctness under a shared SQL Server instance with no external coordination service (no Redis, no distributed lock manager, by explicit project constraint). The database transaction boundary is the *only* coordination primitive available, so every pattern above routes through it rather than inventing an application-level substitute.

## Runtime State Inventory

> Not applicable — this is a greenfield phase (no rename/refactor/migration of existing state). CONTEXT.md confirms: *"None — greenfield project, no application code exists yet (repo currently contains only `.planning/`, `.claude/`, and `SPEC.md`)."* `[VERIFIED: 01-CONTEXT.md:62]`

## Common Pitfalls

### Pitfall 1: `pdo_sqlsrv`'s PHP-version floor silently blocks `Db/Connection.php` from ever connecting

**What goes wrong:** The plan assumes "PHP 8.2+" (SPEC.md's stated floor) and the newest `pdo_sqlsrv` driver can be installed together. They cannot — 5.13.3 requires PHP 8.3+.
**Why it happens:** SPEC.md's stack table (§8.1) states "PHP 8.2+" without cross-checking the driver's actual PHP-version support matrix at spec-authoring time; this is exactly the kind of version-floor mismatch that reads as fine on paper and fails at `composer install`/PECL-install time.
**How to avoid:** Treat this as a hard external dependency to resolve before `Db/Connection.php` is written (already flagged as a STATE.md carried blocker). The plan should include an explicit early task: confirm with university IT which PHP version the target server will run, then pin the matching `pdo_sqlsrv` release (5.13.3 for 8.3+, 5.12.0 for 8.2-only) in the environment-setup documentation/Composer platform config.
**Warning signs:** `pecl install pdo_sqlsrv` or the equivalent prebuilt-DLL install fails silently or installs a version that then fatals with an ABI-mismatch error at `php -m` time.

### Pitfall 2: `ticket_counters` UPDLOCK/HOLDLOCK snippet omits the "row doesn't exist yet" branch

**What goes wrong:** SPEC.md's §5.9 SQL snippet (Pattern 4 above) shows the `UPDATE` path but only comments *"-- if no row exists, INSERT starting at 1"* without SQL for it. A literal copy-paste implementation will silently fail to create a ticket in the first request of every new month, or race on the `INSERT` if two requests hit the missing-row case simultaneously.
**Why it happens:** The comment reads as a completed spec, but it's actually a TODO — easy to miss when moving quickly through migration/query authoring.
**How to avoid:** Explicitly design the missing-row branch as an atomic `MERGE` statement or a `TRY_INSERT`-then-retry-`UPDATE` pattern inside the same transaction, and write a specific test case for "first ticket of a new calendar month, two concurrent requests" alongside AC-14's existing 50-concurrent-request scenario.
**Warning signs:** A test that only exercises `ticket_counters` after a row for the current `period` already exists will never catch this — the plan must include a test that starts from an empty `ticket_counters` table.

### Pitfall 3: Non-idempotent job handlers cause double-processing on crash-retry (structural gap, not yet exercisable but must be designed now)

**What goes wrong:** `bin/scheduler.php`'s stale-lock reset (10-minute `locked_at` timeout, per SPEC.md §5.7) guarantees "at-least-once" delivery. Atomic claiming (Pattern 1) prevents two workers from processing the *same* row *concurrently*, but does nothing to stop the *same* job from running twice *sequentially* after a crash-and-requeue. `[CITED: PITFALLS.md Pitfall 6 — project-level research, cross-referenced against this phase's scope]`
**Why it happens:** "Claim safety" and "handler idempotency" are two different properties that are easy to conflate — developers correctly implement the former and stop, assuming it's sufficient.
**How to avoid:** Even though this phase's only real handler is a no-op stub, design the handler-dispatch layer with an idempotency-check seam now (a base `Handler` interface/decorator that checks "has this job's effect already happened" before dispatching to the concrete handler body) so every handler written in Phase 2+ inherits it for free, per CONTEXT.md's own integration-points note: *"the idempotency-check decorator SPEC.md/PITFALLS.md call for should live here so every future handler inherits it for free."* `[VERIFIED: 01-CONTEXT.md:69]`
**Warning signs:** A `Handlers/` directory with each handler independently deciding (or not deciding) how to detect a duplicate run is a sign the decorator wasn't built at the shared layer.

### Pitfall 4: `job_queue` claim query degrades without a supporting filtered index

**What goes wrong:** The `UPDATE TOP(1) ... WHERE status='pending' AND run_after <= SYSUTCDATETIME()` claim query has no index to use without one, and will fall back to a table scan as `job_queue` accumulates rows — SPEC.md's own indexing example for `tickets` (a filtered index on `sla_resolve_by`) is not mirrored for `job_queue` anywhere in §5.7. `[CITED: PITFALLS.md "Integration Gotchas" table]`
**Why it happens:** The `job_queue` schema table in SPEC.md §5.7 lists columns but no `CREATE INDEX` statement, unlike `tickets` (§5.3) which explicitly lists four indexes.
**How to avoid:** Add a filtered index in the `007_create_job_queue_up.sql` migration: `CREATE INDEX IX_job_queue_claim ON job_queue(run_after) WHERE status = 'pending';` (or a composite `(status, run_after)` index if the filtered form isn't selective enough for the query optimizer in practice — verify with an execution plan once seeded with realistic row counts).
**Warning signs:** `SET STATISTICS IO ON` during the claim query shows a table/clustered-index scan instead of a seek once `job_queue` has more than a few hundred rows.

### Pitfall 5: `ticket_events.reason` "required when `event_type = 'reclassified'`" is a CHECK-constraint-shaped rule that SQL Server CHECK alone can't express cleanly per-column-conditional

**What goes wrong:** SPEC.md §5.6 states *"`reason` | `NVARCHAR(500)` NULL | **Required** when `event_type = 'reclassified'`"* `[VERIFIED: SPEC.md:396]`. A naive `NOT NULL` on `reason` would break every other `event_type`; a missing enforcement leaves this rule as an app-layer-only convention, defeating part of the point of a DB-enforced audit trail.
**Why it happens:** Conditional-NOT-NULL rules are commonly deferred to "the application will check this," which is weaker than what an audit-trail table arguably deserves.
**How to avoid:** Express it as a `CHECK` constraint: `CHECK (event_type <> 'reclassified' OR reason IS NOT NULL)` in the `006_create_ticket_events_up.sql` migration, in addition to (not instead of) an application-layer check — belt-and-suspenders, matching the project's general "DB constraints are the final guard" posture (see Architectural Responsibility Map).
**Warning signs:** A test that inserts a `reclassified` event with `reason = NULL` directly via SQL (bypassing the app layer) succeeds when it should fail.

## Code Examples

### Migration runner shape (`bin/migrate.php`)

```php
<?php
// Source: pattern synthesized from SPEC.md §"repo layout" + standard PHP migration-tracking-table
// idiom (WebSearch cross-checked, no single canonical source — this is this research's composition)
// [ASSUMED beyond the general pattern; exact table/column names are this research's proposal, not SPEC.md-mandated]

// 1. Ensure a schema_migrations tracking table exists (itself migration 000, or created inline
//    by migrate.php on first run — planner's discretion per CONTEXT.md).
// 2. glob('database/migrations/*_up.sql'), sort numerically by the leading NNN prefix.
// 3. For each file whose name is not yet present in schema_migrations:
//      BEGIN TRAN
//        run the file's full SQL batch
//        INSERT INTO schema_migrations (filename, applied_at) VALUES (...)
//      COMMIT (or ROLLBACK + throw on any statement failure — do not partially apply a migration)
// 4. Never re-run an already-applied migration filename.
```
`[ASSUMED]` — no single canonical "the" PHP-on-SQL-Server migration runner exists; this shape is the standard tracking-table idiom used across PHP migration tools (WebSearch corroborated, multiple independent low-authority sources, no official framework doc since this is a hand-rolled runner per SPEC.md's own design, not a framework-provided one). The `_down.sql` execution path (D-01) is dev-only per the locked decision — do not wire it into `bin/migrate.php`'s default `up` behavior; expose it as a separate explicit command (e.g., `php bin/migrate.php down --step=1`) so it can never run accidentally in a scripted/CI context.

### Storage path convention for raw uploads (this phase — no redaction pipeline yet)

```
storage/media/_raw/{uuid}.{ext}
```
`[CITED: SPEC.md §5.4, §10.1]` — *"Originals live in `storage/media/_raw/` and are deleted by the scheduler within 24 hours."* This phase writes to `_raw/` only (Phase 2 adds the `Redactor.php` pass that produces `storage/media/{yyyy}/{mm}/` outputs with `redacted=1`); do **not** implement the 24-hour purge scheduler task this phase since there is no redacted copy yet to purge *toward* — track this as a Phase 2 dependency, not a Phase 1 gap. `ticket_media.storage_path` (per SPEC.md §5.4 schema) should still be written correctly this phase so Phase 2 has a real path to read from.

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|-------------------|---------------|--------|
| `pdo_sqlsrv` 5.10.x/5.11.x generation (older PHP 8.0/8.1-era builds) | `pdo_sqlsrv` 5.12.0 (PHP 8.1/8.2/8.3) as the last 8.2-compatible release, superseded by 5.13.x (PHP 8.3+ only) | 5.12.0 released Feb 2024; 5.13.x line (first GA in 2+ years per prior project STACK.md research) most recently at 5.13.3, 2026-08-07 | Directly determines this phase's PHP-version decision — see Pitfall 1. |

**Deprecated/outdated:** None specific to this phase's scope beyond the driver-version note above; the SQL Server locking/queue idioms (`READPAST`/`UPDLOCK`/`ROWLOCK`) used in SPEC.md §5.7 are long-stable T-SQL features, not subject to version churn.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|-----------------|
| A1 | Migration file naming should use numbered prefixes (`NNN_name_up.sql`/`NNN_name_down.sql`) under `database/migrations/`, reserving the unprefixed `_up.sql` convention for `database/seed/` only | Recommended Project Structure | Low — a naming-convention choice with no functional consequence; easy to rename before any migration runs. Should be confirmed during planning, not assumed silently. |
| A2 | `bin/migrate.php`'s tracking table (`schema_migrations` or similar) design (columns, whether it's itself migration 000 or bootstrap-created) | Code Examples, migration runner shape | Low-Medium — affects `bin/migrate.php`'s internal implementation only (already flagged as Claude's Discretion in CONTEXT.md); wrong choice means a refactor of the runner, not data loss, since no production data exists yet. |
| A3 | `pdo_sqlsrv` `PDOException->errorInfo` array shape/index for the SQL Server error number (assumed `errorInfo[1]`) | Common Pitfalls / Code Examples, Pattern 2 | Medium — if the actual index or shape differs for the installed driver/ODBC version, the deadlock-retry logic silently fails to detect error 1205 and either retries everything (masking real errors) or nothing (defeating the retry). Must be empirically confirmed (`var_dump`) against the real installed driver before this ships. |
| A4 | Job-type payloads for `redact_media`/`classify_ticket` should be enqueued this phase (schema + write path exercised) even though no real handler processes them — dispatched instead to a no-op stub handler | Architecture Patterns, Anti-Patterns to Avoid | Low-Medium — if the planner instead decides not to enqueue any job type this phase (only proving the claim loop with a synthetic test job type), success criterion 3 can still be satisfied, just with less forward-compatible schema exercise. Worth a discuss-phase-style confirmation if the planner disagrees with this framing. |

## Open Questions

1. **Which PHP version will the target server run — 8.2 or 8.3+?**
   - What we know: SPEC.md states "8.2+"; the newest `pdo_sqlsrv` (5.13.3) requires 8.3+; the last 8.2-compatible release is 5.12.0 (Feb 2024, no longer receiving new features).
   - What's unclear: The actual university server's PHP version — this is SPEC.md's own §16 open question, unresolved as of this research pass, and a STATE.md carried blocker.
   - Recommendation: Plan must include an explicit "confirm PHP floor with IT" checkpoint before `Db/Connection.php` is written, with both driver-version branches documented so implementation isn't blocked if the answer arrives mid-phase.

2. **Should the idempotency-check decorator's storage mechanism (how a handler records "this job's effect already happened") be a new table, or reuse `ai_classifications`/`ticket_events` existence checks per job type?**
   - What we know: PITFALLS.md Pitfall 6 recommends checking `ai_classifications` existence for `classify_ticket` and `ticket_events` existence for `notify_line` — i.e., no single generic mechanism, each job type's idempotency check is domain-specific.
   - What's unclear: Whether Phase 1's stub handler/decorator should therefore be a generic *interface* (each future handler implements its own `alreadyProcessed(): bool`) or something more structurally shared.
   - Recommendation: Design the decorator as an interface/contract this phase (cheap, no real handler exists to get it wrong yet), and let Phase 2's real handlers each supply their own domain-specific check — do not try to force a one-size-fits-all idempotency table this phase, since PITFALLS.md's own analysis shows the correct check differs per job type.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|--------------|-----------|---------|-----------|
| PHP (8.2 or 8.3+, TBD) | Everything this phase | ✗ (not installed on this research/dev machine) | — | Must be resolved against the actual deployment target (university server) or a local/CI dev container before implementation — no PHP runtime was found on this research session's machine. |
| `pdo_sqlsrv` extension | `Db/Connection.php` | ✗ | — | Cannot be verified locally this session; install per the resolved PHP-version branch (Pitfall 1) once target environment is known. |
| Microsoft ODBC Driver 17/18 | `pdo_sqlsrv` transport | ✗ | — | System-level install, confirm with university IT (SPEC.md §16 Q8). |
| Microsoft SQL Server 2019+ | All persistence this phase | ✗ (no `sqlcmd`/SQL Server instance found locally) | — | Needs a real or containerized SQL Server instance for any of this phase's work to be tested — recommend a local Docker SQL Server container for dev/CI even before the university's production instance is confirmed, so migrations and the claim-concurrency test can be exercised. |
| Composer | Dependency installation | ✗ (not found on this research/dev machine) | — | Standard PHP tooling; install alongside PHP on the actual dev/target machine. |
| Docker (optional, for local SQL Server dev container) | Local dev/test loop | ✗ (not found on this research/dev machine) | — | Not a hard blocker — the university's actual SQL Server instance could be used directly for dev if IT provides access sooner. |

**Missing dependencies with no fallback:**
- A working SQL Server instance (production, staging, or a local container) is required before any migration or claim-concurrency test can be run for real — this blocks *executing* the plan, not *writing* it.

**Missing dependencies with fallback:**
- PHP/`pdo_sqlsrv`/ODBC driver version: fallback is "pin to whichever branch matches the confirmed target PHP version" (see Pitfall 1) — not blocking plan-writing, blocking only the final environment-confirmation step before `Db/Connection.php` is merged.

## Security Domain

> `security_enforcement: true`, `security_asvs_level: 1` per `.planning/config.json` — included per policy.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|----------------|---------|--------------------|
| V2 Authentication | No (deferred) | This phase's intake API is explicitly "internal" per ROADMAP.md/CONTEXT.md — full JWT/RBAC auth (SPEC.md §10.2) is not yet wired to any external channel (LINE arrives Phase 4). Recommend a minimal internal-only guard (e.g., a shared-secret header, or none, gated behind network/firewall access) rather than building out the full `AuthMiddleware`/`RbacMiddleware` stack prematurely — flag this scoping choice to the planner as a discretion item, not a locked decision. |
| V3 Session Management | No | No session/cookie concept exists this phase — API is stateless, token-based per SPEC.md's overall design; nothing to enforce yet. |
| V4 Access Control | Partial | `ticket_events`'s `DENY UPDATE, DELETE` (Pattern 3) *is* an access-control control relevant this phase — enforced at the DB-object level for the application's SQL login, independent of any HTTP-layer RBAC. |
| V5 Input Validation | Yes | `finfo_file()` MIME-sniffing on every upload (never trust `$_FILES['type']`, per SPEC.md §6.1, `[VERIFIED: SPEC.md:497]`); PDO **prepared statements only** for every query touching user input — string-concatenated SQL is forbidden without exception (`[VERIFIED: SPEC.md:904]` — *"SQL injection | **PDO prepared statements only.** String-concatenated SQL is forbidden without exception."*); server-side re-validation of `text`/`images[]` presence (AC-3: reject with `422 TICKET_EMPTY_INPUT`, and **no row created** on rejection). |
| V6 Cryptography | Partial | No PII-specific crypto this phase (LINE `userId` hashing is Phase 4 scope — `reporter_channel` values this phase are `web`/`phone`/`walkin` per an internal intake path, not `line`). DB transport encryption (`DB_ENCRYPT=yes`, `DB_TRUST_SERVER_CERT` per SPEC.md §8.3 `.env.example`) is an ops/connection-string concern this phase's `Db/Connection.php` must honor, not a code-level crypto implementation. |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|-----------------------|
| SQL injection via ticket text/location fields | Tampering | PDO prepared statements exclusively (`Db/Connection.php` should make raw string-concatenated queries structurally awkward to write, e.g. by only exposing a `query(string $sql, array $params)` helper, never raw `PDO::query()` with interpolated strings). |
| Polyglot file upload (valid image header + embedded payload) served back with a permissive `Content-Type` | Tampering / Elevation of Privilege | `[CITED: PITFALLS.md "Security Mistakes" table]` — force `Content-Type` from the *validated* `finfo_file()` result, never the raw uploaded byte stream's internal claims; store outside the webroot (already SPEC.md's design) so files are never directly web-addressable regardless of content. Full re-encoding through Imagick (which strips non-image payloads) is a Phase 2 concern once Imagick is in scope — this phase's mitigation is MIME validation + webroot exclusion only. |
| Uncontrolled `ticket_no`/counter race producing duplicate or skipped ticket numbers under load | Tampering / Denial of Service (operational) | Pattern 4 (UPDLOCK/HOLDLOCK) + Pitfall 2's missing-row branch — directly tested by AC-14. |
| Audit-log tampering by a compromised or buggy application code path | Repudiation | Pattern 3 (`DENY UPDATE, DELETE` at the DB-object level) — the control that exists specifically so an application-layer bug or compromise cannot rewrite history, which is the whole point of AUDIT-01. |
| Job-queue payload injection (a crafted `job_queue.payload` causing unintended handler behavior once real handlers exist in Phase 2) | Tampering | Out of this phase's direct scope (no real handler consumes payload content yet), but the schema decision to store `payload` as `NVARCHAR(MAX)` with `CHECK (ISJSON(payload)=1)` (per SPEC.md §5.7, `[VERIFIED: SPEC.md:407]`) should be enforced in the `007_create_job_queue_up.sql` migration now so Phase 2's handlers inherit a guarantee that `payload` is at least well-formed JSON. |

## Sources

### Primary (HIGH confidence)
- SPEC.md v2.0 (2026-08-18) — §5 (data model, all subsections), §5.7 (`job_queue`), §5.9 (`ticket_no`), §6.1 (intake API), §8.1/8.2/8.4/8.5 (stack, structure, worker supervision), §10.2 (system security), §16 (open questions) — authoritative project source, `Read` in full this session.
- `.planning/phases/01-foundations-data-layer-job-queue-infrastructure/01-CONTEXT.md` — locked decisions D-01–D-04, discretion items, deferred ideas — `Read` in full this session.
- `.planning/REQUIREMENTS.md`, `.planning/STATE.md`, `.planning/ROADMAP.md` — phase scope, traceability, carried blockers — `Read` in full this session.

### Secondary (MEDIUM confidence)
- `.planning/research/SUMMARY.md`, `STACK.md`, `PITFALLS.md`, `ARCHITECTURE.md` — project-level research completed 2026-08-18, `Read` in full this session; cross-referenced and re-verified where claims are time-sensitive (package versions, driver compatibility).
- Packagist `p2` API (`repo.packagist.org/p2/<vendor>/<package>.json`) — direct registry query this session (2026-08-20) for `vlucas/phpdotenv` (v5.6.4), `ramsey/uuid` (4.9.3), `monolog/monolog` (3.10.0), `phpunit/phpunit` (13.3.1) current versions and publish dates.
- WebSearch, this session (2026-08-20): `pdo_sqlsrv` version/PHP-floor (PECL, Microsoft Community Hub, GitHub `microsoft/msphpsql` releases cross-checked) — MEDIUM confidence, matches and updates prior STACK.md finding.
- WebSearch, this session: SQL Server `UPDATE TOP(1) WITH (ROWLOCK, READPAST, UPDLOCK) OUTPUT` claim pattern (Erik Darling "Building Reusable Queues", MSSQLTips "Processing Data Queues in SQL Server with READPAST and UPDLOCK") — MEDIUM confidence, confirms SPEC.md §5.7's SQL is the correct idiom.
- WebSearch, this session: `NEWSEQUENTIALID()` vs `NEWID()` fragmentation behavior (Microsoft Q&A, MSSQLTips, sqlserverscience.com) — MEDIUM confidence.
- WebSearch, this session: `DENY UPDATE, DELETE` / SQL Server Ledger append-only tables (DZone, designgurus.io, MSSQLTips, Microsoft Learn "DENY (Transact-SQL)") — MEDIUM confidence.
- WebSearch, this session: PHP long-running CLI worker patterns, systemd `Restart=always` (nazarboyko.com, fastfox.pro, Tideways blog) — MEDIUM confidence, confirms SPEC.md §8.5's existing design.
- WebSearch, this session: PHP migration-runner-with-tracking-table pattern (PHP Classes, peerdh.com, DEV Community, various GitHub repos) — LOW-MEDIUM confidence, no single canonical/official source since this is a hand-rolled tool by project design, not a framework feature.
- Microsoft Learn `MSSQLSERVER_1205` — deadlock error/SQLSTATE mapping — referenced via WebSearch summary, not independently WebFetched this session; treat the exact `errorInfo` array shape as `[ASSUMED]` per Assumption A3 until empirically confirmed against the installed driver.

### Tertiary (LOW confidence)
- None used directly in this phase's claims beyond what's folded into the MEDIUM-confidence WebSearch items above.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH for the schema/migration/queue design (directly from authoritative SPEC.md); MEDIUM for exact driver/package version pins (WebSearch/Packagist, re-verify with `composer show -a`/PECL at actual implementation time since these numbers move)
- Architecture: HIGH — SPEC.md is a pre-existing, detailed, authoritative design for this exact phase's scope; this research validates and adds PDO-execution-shape/PHP-code detail SPEC.md's SQL-only snippets don't cover
- Pitfalls: MEDIUM-HIGH — Pitfalls 1, 2, 4, 5 are directly grounded in this session's `Read` of SPEC.md's exact text plus WebSearch-confirmed external facts (driver version, indexing gotcha); Pitfall 3 is carried from prior project-level PITFALLS.md research, scoped down to what's actionable this phase

**Research date:** 2026-08-20
**Valid until:** ~14 days for the `pdo_sqlsrv`/PHP-version-floor finding specifically (Microsoft has shipped three driver point releases inside the last ~2.5 years at an accelerating cadence — re-verify via `pecl.php.net/package/pdo_sqlsrv` immediately before `Db/Connection.php` implementation begins, not just at planning time); ~30 days for the rest (SQL Server locking idioms, `DENY` syntax, and the Composer package versions are stable/low-churn).

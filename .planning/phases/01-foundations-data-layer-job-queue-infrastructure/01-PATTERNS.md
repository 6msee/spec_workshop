# Phase 1: Foundations, Data Layer & Job Queue Infrastructure - Pattern Map

**Mapped:** 2026-08-20
**Files analyzed:** 27 (new files; 0 modified — greenfield)
**Analogs found:** 0 / 27 in-repo — **this is a confirmed greenfield repository**

## Greenfield Notice (read first)

The repository contains only `.planning/`, `.claude/`, and `SPEC.md` at the root. Verified this session:

```
find . -iname "*.php" -not -path './.git/*'   → 0 results
find . -iname "*.sql" -not -path './.git/*'   → 0 results
find . -maxdepth 3 -type d ...                → only ./.claude (plus .git, .planning)
```

There is **no existing application code** to serve as an in-repo analog for any file this phase creates. Per the orchestrator's framing, this PATTERNS.md substitutes **`SPEC.md`** (the authoritative, pre-existing technical spec — §5 Data Model, §6 API Specification, §8 Tech Stack/repo layout, §10 Security) and **`01-RESEARCH.md`** (PDO/`pdo_sqlsrv` execution shapes, deadlock-retry composition, migration-runner shape) as the pattern source in place of codebase analogs. Every "Analog" cited below is a **spec/research excerpt with exact line numbers**, not a code file — the planner should treat these as the template to write the first real implementation against, not as "existing code to copy."

No `Grep`/`Glob` search for `class.*Controller`, `router\.(get|post...)`, `**/controllers/**`, `**/services/**` etc. produced any hits, confirming there is nothing to rank by match quality. All entries below carry **Match Quality: none (greenfield)**.

## File Classification

| New File | Role | Data Flow | Pattern Source | Match Quality |
|----------|------|-----------|-----------------|---------------|
| `composer.json` | config | — | SPEC.md §8.2 (lines 622-644) | none (greenfield) — spec gives verbatim JSON |
| `.env.example` | config | — | SPEC.md §8.3 (lines 648-698) | none (greenfield) — spec gives verbatim keys |
| `public/index.php` | route (front controller) | request-response | SPEC.md §6 (472-561), §8.4 (707-709) | none (greenfield) |
| `src/Http/Router.php` | route | request-response | SPEC.md §8.4 (728), §6.2 endpoint table (513-530) | none (greenfield) |
| `src/Http/Request.php` | utility | request-response | SPEC.md §6.1 (478-497) | none (greenfield) |
| `src/Http/Response.php` | utility | request-response | SPEC.md §6.3 error envelope (532-559) | none (greenfield) |
| `src/Http/Controllers/TicketController.php` | controller | request-response (+ CRUD write) | SPEC.md §6.1 (478-509), §5.9 (454-468); RESEARCH.md Architecture Diagram (124-141) | none (greenfield) |
| `src/Domain/Taxonomy.php` | model (constants) | — | SPEC.md §5.0 (269-281) "Enums" row; §4.1 (not read this pass, referenced by §5.3/5.2 CHECK columns) | none (greenfield) |
| `src/Db/Connection.php` | service | CRUD (+ retry) | RESEARCH.md Pattern 2 (262-287), SPEC.md §8.3 `DB_*` vars (655-663) | none (greenfield) |
| `src/Queue/JobQueue.php` | service | event-driven (enqueue/claim) | SPEC.md §5.7 (401-431); RESEARCH.md Pattern 1 (232-260) | none (greenfield) |
| `src/Queue/Handlers/HandlerInterface.php` (idempotency seam) | service (contract) | event-driven | RESEARCH.md Pitfall 3 (361-366), Open Question 2 (435-438) | none (greenfield) — interface shape is this phase's own design, not spec-dictated |
| `src/Queue/Handlers/NoopHandler.php` | service (stub) | event-driven | RESEARCH.md Architecture Diagram (162-165), Anti-Patterns (326) | none (greenfield) |
| `src/Support/Env.php` | utility | — | SPEC.md §8.3 env var list (648-698); RESEARCH.md `vlucas/phpdotenv` entry (74) | none (greenfield) |
| `src/Support/Logger.php` | utility | — | SPEC.md §8.1 "Logging" row (620); RESEARCH.md `monolog/monolog` entry (76) | none (greenfield) |
| `bin/migrate.php` | utility (CLI) | batch | RESEARCH.md "Migration runner shape" (384-402) | none (greenfield) — explicitly `[ASSUMED]` in research, no canonical source |
| `bin/worker.php` | service (CLI) | event-driven (polling) | SPEC.md §5.7 claim SQL (417-429), §8.5 (806-825); RESEARCH.md Pattern 1 (232-260), Architecture Diagram (154-168) | none (greenfield) |
| `bin/scheduler.php` | service (CLI) | batch | SPEC.md §5.7 stale-lock note (431), §8.5 (806-825); RESEARCH.md Architecture Diagram (170-176) | none (greenfield) |
| `database/migrations/001_create_buildings_up.sql` (+`_down.sql`) | migration | batch | SPEC.md §5.1 (283-294) | none (greenfield) |
| `database/migrations/002_create_assets_up.sql` (+`_down.sql`) | migration | batch | SPEC.md §5.2 (296-311) | none (greenfield) |
| `database/migrations/003_create_tickets_up.sql` (+`_down.sql`) | migration | batch | SPEC.md §5.3 (313-353, incl. index block) | none (greenfield) |
| `database/migrations/004_create_ticket_media_up.sql` (+`_down.sql`) | migration | batch | SPEC.md §5.4 (355-369) | none (greenfield) |
| `database/migrations/005_create_ai_classifications_up.sql` (+`_down.sql`) | migration | batch | SPEC.md §5.5 (371-384) — schema only, no writer this phase | none (greenfield) |
| `database/migrations/006_create_ticket_events_up.sql` (+`_down.sql`) | migration | batch | SPEC.md §5.6 (386-399); RESEARCH.md Pattern 3 (289-303), Pitfall 5 (375-380) | none (greenfield) |
| `database/migrations/007_create_job_queue_up.sql` (+`_down.sql`) | migration | batch | SPEC.md §5.7 (401-431); RESEARCH.md Pitfall 4 (368-373, filtered index) | none (greenfield) |
| `database/migrations/008_create_support_tables_up.sql` (+`_down.sql`) | migration | batch | SPEC.md §5.9 (454-468, `ticket_counters`); §8.4 (788, names `holidays`/`rate_limits`/`idempotency_keys`) | none (greenfield) — column-level detail for the 3 non-counter tables is `[ASSUMED]`, not spelled out in SPEC.md body text read this pass |
| `database/seed/buildings_up.sql` | migration (seed) | batch | CONTEXT.md D-02 (22-24); SPEC.md §5.1 | none (greenfield) |
| `tests/Unit/*` (Connection retry, JobQueue claim-concurrency, ticket_no race) | test | — | RESEARCH.md Pitfall 2 (354-359), Pattern 2 (262-287) | none (greenfield) |

## Pattern Assignments

### `src/Db/Connection.php` (service, CRUD + retry)

**Pattern source:** RESEARCH.md Pattern 2, lines 262-287; SPEC.md §8.3 lines 655-663 (`DB_*` env vars); SPEC.md §10.2 line 904 ("PDO prepared statements only").

**Deadlock-retry core pattern** (RESEARCH.md:270-285, verbatim):
```php
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
**Warning (carry into implementation):** the `errorInfo[1]` index is `[ASSUMED]` (RESEARCH.md Assumption A3, line 425) — `var_dump($e->errorInfo)` against the real installed `pdo_sqlsrv` driver before trusting this.

**Prepared-statement discipline:** SPEC.md:904 — *"SQL injection | PDO prepared statements only. String-concatenated SQL is forbidden without exception."* `Connection.php` should expose only a `query(string $sql, array $params)`-shaped helper (RESEARCH.md:475) so raw interpolated `PDO::query()` calls are structurally hard to write elsewhere in the codebase.

**Env-driven config to read:** SPEC.md:655-663 (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_ENCRYPT`, `DB_TRUST_SERVER_CERT`).

---

### `src/Queue/JobQueue.php` (service, event-driven)

**Pattern source:** SPEC.md §5.7 lines 417-429 (claim SQL, verbatim); RESEARCH.md Pattern 1 lines 232-260 (PDO execution shape).

**Claim SQL** (SPEC.md:420-429, verbatim — copy exactly, never `SELECT` then `UPDATE`):
```sql
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

**PDO execution shape** (RESEARCH.md:252-259):
```php
$stmt = $pdo->prepare($claimSql);
$stmt->bindValue(':workerId', $workerId, PDO::PARAM_STR);
$stmt->execute();
$claimed = $stmt->fetchAll(PDO::FETCH_ASSOC); // 0 or 1 row from the OUTPUT clause
if (empty($claimed)) {
    // nothing pending — sleep WORKER_POLL_SECONDS and retry
}
```
Note: this is an `UPDATE`, not an `INSERT` — do not use `PDO::lastInsertId()`; read the claimed row from the `OUTPUT` clause's result set via `fetchAll()`/`fetch()`.

**Enqueue side:** no SPEC.md SQL snippet exists for the `INSERT INTO job_queue`; write it against the column list in SPEC.md §5.7 (403-415), enforcing `CHECK (ISJSON(payload)=1)` (line 407, `[VERIFIED: SPEC.md:407]`) at the migration level so `JobQueue::enqueue()` inherits the guarantee from the DB, not just app-layer discipline.

---

### `src/Queue/Handlers/HandlerInterface.php` + `NoopHandler.php` (service, event-driven)

**Pattern source:** RESEARCH.md Pitfall 3 lines 361-366, Open Question 2 lines 435-438, Architecture Diagram lines 162-165, Anti-Patterns line 326.

No SQL/code excerpt exists for this — it is this phase's own structural design, called for explicitly by CONTEXT.md's integration-points note (line 69: *"the idempotency-check decorator ... should live here so every future handler inherits it for free"*). Design guidance from research (not copy-paste code):
- Define an interface/contract (e.g., `alreadyProcessed(): bool` + `handle(array $payload): void`) that every future `Handlers/*` class implements — RESEARCH.md is explicit that idempotency checks are **domain-specific per job type** (`ai_classifications` existence for `classify_ticket`, `ticket_events` existence for `notify_line`), so do **not** build one generic idempotency table this phase (RESEARCH.md:435-438).
- `NoopHandler.php` this phase only needs to prove the claim→dispatch→mark-done loop (success criterion 3, "no double-processing on concurrent workers or crash-retry") — it has no real domain idempotency check to perform yet since there's no real side effect.

---

### `bin/worker.php` (service CLI, event-driven polling loop)

**Pattern source:** SPEC.md §5.7 lines 417-431, §8.5 lines 806-825 (systemd unit); RESEARCH.md Architecture Diagram lines 154-168.

**Loop shape** (RESEARCH.md:154-168, this phase's stub-handler version):
```
LOOP every WORKER_POLL_SECONDS:
  1. Claim via the atomic UPDATE...OUTPUT statement (see JobQueue.php pattern above)
  2. If claimed: dispatch to Handlers\NoopHandler (Phase 1 — proves atomicity only)
  3. Mark job status='done' or 'failed'
  4. Exit after N jobs / T minutes → systemd Restart=always respawns
```
**Systemd unit** (SPEC.md:811-816, verbatim — D-04 locks this to a single non-templated instance this phase, not the `@service` form SPEC.md sketches for future multi-worker scale-out):
```ini
[Service]
ExecStart=/usr/bin/php /var/www/up-fix/bin/worker.php --id=%i
Restart=always
RestartSec=5
```
**Voluntary-exit requirement** (SPEC.md:825): *"the worker must be idempotent and must exit voluntarily after 1,000 jobs or 30 minutes so the process is restarted and memory reclaimed."*

---

### `bin/scheduler.php` (service CLI, batch)

**Pattern source:** SPEC.md §5.7 line 431; RESEARCH.md Architecture Diagram lines 170-176.

This phase's scheduler responsibility is scoped down to **stale-lock recovery only** (no SLA escalation, auto-close, or `_raw` purge yet — those depend on features not built this phase):
```sql
-- Source: pattern derived from SPEC.md:431 ("Jobs stuck in running ... reset to pending")
UPDATE job_queue SET status = 'pending'
WHERE status = 'running' AND locked_at < DATEADD(MINUTE, -@staleMinutes, SYSUTCDATETIME());
```
`WORKER_STALE_LOCK_MINUTES` env var (SPEC.md:697) supplies `@staleMinutes` (default 10, per SPEC.md:431 "older than 10 minutes").

---

### `bin/migrate.php` (utility CLI, batch)

**Pattern source:** RESEARCH.md "Migration runner shape" lines 384-402 — explicitly `[ASSUMED]`, no canonical single source; this is research's own composition, not spec-mandated.

```
1. Ensure a schema_migrations tracking table exists (migration 000 or bootstrap-created — planner's discretion per CONTEXT.md).
2. glob('database/migrations/*_up.sql'), sort numerically by the leading NNN prefix.
3. For each file not yet in schema_migrations:
     BEGIN TRAN
       run the file's full SQL batch
       INSERT INTO schema_migrations (filename, applied_at) VALUES (...)
     COMMIT (or ROLLBACK + throw on any statement failure)
4. Never re-run an already-applied migration filename.
```
**Down-script safety note:** D-01 requires paired `_down.sql` files, but per RESEARCH.md:402, the `down` path must be a **separate explicit CLI command** (e.g. `php bin/migrate.php down --step=1`), never wired into the default `up` behavior, so it can't run accidentally in a scripted/CI context.

---

### `database/migrations/003_create_tickets_up.sql` (migration)

**Pattern source:** SPEC.md §5.3 lines 313-353 (verbatim column table + index block).

**Index block to copy verbatim** (SPEC.md:347-353):
```sql
CREATE INDEX IX_tickets_queue    ON tickets(status, priority, created_at);
CREATE INDEX IX_tickets_building ON tickets(building_id, category, status);
CREATE INDEX IX_tickets_assignee ON tickets(assigned_to, status);
CREATE INDEX IX_tickets_sla_open ON tickets(sla_resolve_by)
    WHERE status NOT IN ('closed', 'cancelled');   -- filtered index
```
All new tables follow §5.0 conventions (SPEC.md:269-281): `NVARCHAR` (never `VARCHAR`) for Thai text, `UNIQUEIDENTIFIER DEFAULT NEWSEQUENTIALID()` PKs, `DATETIME2(0)` UTC timestamps via `SYSUTCDATETIME()`, `BIT` booleans, `NVARCHAR(n)` + `CHECK` for enums, `NVARCHAR(MAX)` + `CHECK (ISJSON(col)=1)` for JSON columns, `DECIMAL(12,2)` for money, `DECIMAL(10,7)` for coordinates.

---

### `database/migrations/006_create_ticket_events_up.sql` (migration — AUDIT-01)

**Pattern source:** SPEC.md §5.6 lines 386-399; RESEARCH.md Pattern 3 lines 289-303 (DENY syntax correction), Pitfall 5 lines 375-380 (conditional CHECK).

**Corrected DENY statement** (RESEARCH.md:301, corrects SPEC.md's non-valid-T-SQL prose at line 399):
```sql
DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app;
```
Must be the final statement of this same migration file — not a separate manual step — so a fresh environment built by `bin/migrate.php` alone has the AUDIT-01 guarantee.

**Conditional-required CHECK** (RESEARCH.md:379, addresses SPEC.md:396's prose-only rule):
```sql
CHECK (event_type <> 'reclassified' OR reason IS NOT NULL)
```

---

### `database/migrations/007_create_job_queue_up.sql` (migration)

**Pattern source:** SPEC.md §5.7 lines 401-415; RESEARCH.md Pitfall 4 lines 368-373.

**Missing index SPEC.md doesn't show — add explicitly** (RESEARCH.md:372):
```sql
CREATE INDEX IX_job_queue_claim ON job_queue(run_after) WHERE status = 'pending';
```

---

### `database/migrations/008_create_support_tables_up.sql` (migration — `ticket_counters`)

**Pattern source:** SPEC.md §5.9 lines 454-468; RESEARCH.md Pattern 4 lines 305-320, Pitfall 2 lines 354-359.

**UPDLOCK/HOLDLOCK counter SQL** (SPEC.md:459-466, verbatim — the `TicketController.php` ticket-creation transaction copies this, not the migration file itself, but the migration must create the `ticket_counters` table this SQL depends on):
```sql
BEGIN TRAN;
  UPDATE ticket_counters WITH (UPDLOCK, HOLDLOCK)
  SET last_no = last_no + 1
  OUTPUT inserted.last_no
  WHERE period = @period;   -- '202608'
  -- if no row exists, INSERT starting at 1
COMMIT;
```
**Gap the plan must fill (RESEARCH.md Pitfall 2, lines 354-359):** SPEC.md's comment *"if no row exists, INSERT starting at 1"* is not real SQL. Design an atomic `MERGE` or `TRY_INSERT`-then-retry-`UPDATE` for the missing-`period`-row branch inside the same transaction, and write a test that starts from an **empty** `ticket_counters` table (not one that already has a row for the current period) plus a concurrent-first-request-of-month test — a table that already has today's period row will never exercise this bug.

---

## Shared Patterns

### DB Conventions (applies to every migration file)
**Source:** SPEC.md §5.0, lines 269-281 (verbatim table).
- Text: always `NVARCHAR`, never `VARCHAR` (Thai support)
- PKs: `UNIQUEIDENTIFIER DEFAULT NEWSEQUENTIALID()`
- Timestamps: `DATETIME2(0)`, UTC via `SYSUTCDATETIME()`
- Booleans: `BIT`
- Enums: `NVARCHAR(n)` + `CHECK`, mirrored as PHP constants in `src/Domain/Taxonomy.php`
- JSON: `NVARCHAR(MAX)` + `CHECK (ISJSON(col)=1)`
- Money: `DECIMAL(12,2)`, never `FLOAT`
- Coordinates: `DECIMAL(10,7)`

**Apply to:** all 8 `database/migrations/*_up.sql` files.

### Prepared Statements Only (applies to every DB-touching PHP file)
**Source:** SPEC.md §10.2, line 904.
**Apply to:** `Db/Connection.php`, `Queue/JobQueue.php`, `Http/Controllers/TicketController.php`, `bin/worker.php`, `bin/scheduler.php`, `bin/migrate.php`.

### Deadlock Retry (error 1205)
**Source:** RESEARCH.md Pattern 2, lines 262-287 (see full excerpt under `Db/Connection.php` above).
**Apply to:** `Db/Connection.php` (the shared wrapper), and any write transaction that contends under load — most directly the `ticket_counters` UPDLOCK/HOLDLOCK block in `TicketController.php`'s ticket-creation transaction and any `job_queue` claim under load in `bin/worker.php`.

### Atomic Claim, Never `SELECT` Then `UPDATE`
**Source:** SPEC.md §5.7 lines 417-429; RESEARCH.md Pattern 1, Anti-Patterns line 324.
**Apply to:** `Queue/JobQueue.php`'s `claim()` method — the only place this pattern is needed, but it must be followed with zero exceptions.

### Standard Error Envelope
**Source:** SPEC.md §6.3, lines 532-559 (verbatim JSON shape + HTTP/code table).
```json
{
  "error": {
    "code": "TICKET_EMPTY_INPUT",
    "message_th": "กรุณาส่งข้อความหรือรูปภาพอย่างน้อย 1 อย่าง",
    "message_en": "At least one of text or images is required",
    "request_id": "req_01J…"
  }
}
```
**Apply to:** `Http/Response.php` (the shared formatter), `Http/Controllers/TicketController.php`, `public/index.php`'s `set_exception_handler()` catch-all (SPEC.md:559: *"Use `set_exception_handler()` to convert everything into the envelope above."*).

### Immutable Audit Trail via `DENY`
**Source:** SPEC.md §5.6 line 399 (corrected syntax in RESEARCH.md Pattern 3, lines 289-303).
**Apply to:** `database/migrations/006_create_ticket_events_up.sql` only — this is the direct implementation of AUDIT-01, this phase's one mapped requirement.

### MIME-Type Validation (Never Trust Client `$_FILES['type']`)
**Source:** SPEC.md §6.1 line 497 (`[VERIFIED: SPEC.md:497]`).
**Apply to:** `Http/Controllers/TicketController.php`'s media-upload handling (raw upload to `storage/media/_raw/` this phase, per RESEARCH.md "Storage path convention," lines 404-409).

## No Analog Found

Every file in this phase falls into this category by definition (greenfield repo, no application code exists). Restating per the output-format contract rather than leaving the section implicitly empty:

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| All 27 files listed in File Classification above | (varies) | (varies) | No PHP or SQL files exist anywhere in the repository outside `.claude/`/`.planning/` (confirmed via `find` this session); SPEC.md and RESEARCH.md substitute as the pattern source, as detailed in Pattern Assignments above — planner should treat those excerpts as authoritative rather than searching further for in-repo analogs. |

## Open Gaps for the Planner (carried from RESEARCH.md, relevant to pattern fidelity)

- **`errorInfo[1]` index for SQL Server error 1205** is `[ASSUMED]` in the deadlock-retry pattern — must be empirically confirmed (`var_dump($e->errorInfo)`) against the real installed `pdo_sqlsrv`/ODBC driver before `Db/Connection.php` ships (RESEARCH.md Assumption A3).
- **`ticket_counters` missing-row branch** has no real SQL in SPEC.md — the plan must design and test it explicitly (RESEARCH.md Pitfall 2).
- **`database/migrations/008_create_support_tables_up.sql`**'s three non-counter tables (`holidays`, `rate_limits`, `idempotency_keys`) are named in SPEC.md's repo-layout comment (line 788) only — no column-level schema for them was found in the SPEC.md sections read this pass (§5.1-5.9 cover buildings/assets/tickets/ticket_media/ai_classifications/ticket_events/job_queue/ticket_counters only). Planner should re-check SPEC.md §4 (Requirements, not fully read this pattern-mapping pass) or treat these three tables' exact columns as an implementation-time design decision, not a verbatim spec copy.
- **`pdo_sqlsrv` PHP-version floor** (8.2 vs 8.3+) is unresolved — affects nothing about the *code pattern* itself but gates which driver version to document in `.env.example`/README setup instructions (RESEARCH.md Pitfall 1).

## Metadata

**Analog search scope:** entire repository root (`find . -iname "*.php"`, `find . -iname "*.sql"`, `find . -maxdepth 3 -type d`) — confirmed empty of application code.
**Files scanned:** 0 in-repo code files (none exist); 2 spec/research documents read in full or targeted sections (`SPEC.md` §5, §6, §8, §10; `01-RESEARCH.md` in full).
**Pattern extraction date:** 2026-08-20

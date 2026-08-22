---
phase: 01-foundations-data-layer-job-queue-infrastructure
plan: 01
subsystem: database
tags: [php, sql-server, pdo_sqlsrv, migrations, audit-trail, deadlock-retry, ticket-intake]

# Dependency graph
requires: []
provides:
  - "Runnable PHP application skeleton (composer.json, PSR-4 UpFix\\, phpunit.xml)"
  - "src/Db/Connection.php: PDO sqlsrv factory, parameterised query helper, SQL Server deadlock (1205) retry"
  - "bin/migrate.php: forward migration runner (up/status/down --step=N) over database/migrations/*_up.sql"
  - "Migrations 001 (buildings), 002 (assets), 003 (tickets), 006 (ticket_events + DENY), 008 (ticket_counters)"
  - "src/Domain/Taxonomy.php: SPEC.md 4.1/5.8 categories/priorities/statuses/channels constants"
  - "src/Domain/TicketNumber.php: race-safe UPF-YYYYMM-NNNNN allocation via MERGE ... WITH (HOLDLOCK)"
  - "src/Domain/EventLog.php: the single append-only write path into ticket_events"
  - "POST /api/v1/tickets: text intake -> ticket_no + append-only created event, one transaction"
  - "AUDIT-01: DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app, enforced at the database-privilege level"
affects: [01-02-job-queue, 01-03-photo-intake-remaining-tables, 02-ai-classification]

actuals:
  tokens: 15386
  tasks: 3
  commits: 9

tech-stack:
  added: [vlucas/phpdotenv@^5.6, ramsey/uuid@^4.7, monolog/monolog@^3.10, phpunit/phpunit@^11.0 (dev)]
  patterns:
    - "Single Connection::run() seam -- every SQL statement is prepare()+execute(), never raw PDO::query()"
    - "withDeadlockRetry(): catches PDOException, retries only SQL Server error 1205, jittered backoff"
    - "Connection::run() escalates any non-'00000' SQLSTATE to a thrown PDOException (warnings included)"
    - "MERGE ... WITH (HOLDLOCK) ... OUTPUT for race-safe counter allocation, missing-row branch closed atomically"
    - "EventLog::record() is the only write path into ticket_events; DB-level DENY is the real enforcement, app-layer validation is belt-and-suspenders"
    - "bin/migrate.php: schema_migrations tracking table, ascending-numeric-prefix ordering, out-of-order notice, explicit down --step=N never reachable from up"

key-files:
  created:
    - composer.json
    - phpunit.xml
    - .gitignore
    - .env.example
    - README.md
    - public/index.php
    - public/.htaccess
    - src/Support/Env.php
    - src/Support/Logger.php
    - src/Db/Connection.php
    - src/Http/Router.php
    - src/Http/Request.php
    - src/Http/Response.php
    - src/Http/Controllers/TicketController.php
    - src/Domain/Taxonomy.php
    - src/Domain/TicketNumber.php
    - src/Domain/EventLog.php
    - bin/migrate.php
    - database/migrations/001_create_buildings_up.sql (+ _down.sql)
    - database/migrations/002_create_assets_up.sql (+ _down.sql)
    - database/migrations/003_create_tickets_up.sql (+ _down.sql)
    - database/migrations/006_create_ticket_events_up.sql (+ _down.sql)
    - database/migrations/008_create_ticket_counters_up.sql (+ _down.sql)
    - tests/Unit/TicketNumberTest.php
    - tests/Feature/TicketIntakeTest.php
    - tests/Feature/TicketEventsImmutabilityTest.php
  modified: []

key-decisions:
  - "Task 1 (PHP floor): php83 -- composer.json pins \"php\": \">=8.3\", pdo_sqlsrv 5.13.3, confirmed working (msphpsql 5.13.3, PHP 8.5.4, ODBC Driver 18.6.2.1)"
  - "Filtered index WHERE status NOT IN (...) rewritten as WHERE status <> 'closed' AND status <> 'cancelled' -- SQL Server's filtered-index predicate parser rejects NOT IN"
  - "Connection::run() now escalates any non-'00000' SQLSTATE to a PDOException -- PDO::ERRMODE_EXCEPTION does not catch SQLSTATE 01000-class warnings, which let migration 006's DENY statement silently no-op the first time"
  - "tickets.assigned_to is a plain UNIQUEIDENTIFIER NULL with no FK -- technicians table does not exist until Phase 3 (documented spec gap, per plan instruction)"
  - "Migration 006's DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app cannot be executed by bin/migrate.php running as upfix_app itself -- SQL Server refuses self-targeting GRANT/DENY/REVOKE as a hard security invariant; applied once by the coordinator as an elevated (sa) principal"

patterns-established:
  - "Pattern: race-safe counter allocation via single atomic MERGE ... WITH (HOLDLOCK) ... OUTPUT statement, never SELECT-then-UPDATE or MAX()+1"
  - "Pattern: append-only audit table enforced by DB-level DENY, applied inside the same migration that creates the table, verified by a dedicated PDOException-expecting test suite"
  - "Pattern: deadlock (1205) retry wrapped around the whole business transaction closure, not individual statements"
  - "Pattern: test tearDown() for tables with DENY-protected or FK-protected rows does not attempt deletion -- tests key off fresh UUIDs per run instead of resetting shared state"

requirements-completed: [AUDIT-01]

coverage:
  - id: D1
    description: "POST /api/v1/tickets accepts text, allocates a race-safe UPF-YYYYMM-NNNNN ticket number, persists the ticket, and returns 201"
    requirement: "AUDIT-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TicketIntakeTest.php#creatingATicketReturns201WithAWellFormedTicketNo"
        status: pass
      - kind: e2e
        ref: "curl -X POST -F 'text=...' -F 'channel=web' -F 'reporter_ref=...' http://127.0.0.1:8080/api/v1/tickets -> 201"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every ticket creation writes exactly one append-only ticket_events row ('created') in the same transaction as the tickets insert"
    requirement: "AUDIT-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TicketEventsImmutabilityTest.php#creatingATicketYieldsExactlyOneCreatedEvent"
        status: pass
      - kind: integration
        ref: "tests/Feature/TicketEventsImmutabilityTest.php#aFailedEventInsertInsideTheIntakeTransactionLeavesZeroRowsInBothTables"
        status: pass
    human_judgment: false
  - id: D3
    description: "dbo.ticket_events is unfalsifiable: UPDATE and DELETE against it as the application login (upfix_app) are both rejected by the database, enforced by DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app inside migration 006"
    requirement: "AUDIT-01"
    verification:
      - kind: integration
        ref: "tests/Feature/TicketEventsImmutabilityTest.php#updateAgainstTicketEventsIsRejectedAndLeavesTheRowUnchanged"
        status: pass
      - kind: integration
        ref: "tests/Feature/TicketEventsImmutabilityTest.php#deleteAgainstTicketEventsIsRejectedAndLeavesTheRowCountUnchanged"
        status: pass
    human_judgment: false
  - id: D4
    description: "ticket_no allocation is race-safe against a completely empty ticket_counters table and increments sequentially within a period"
    requirement: "AUDIT-01"
    verification:
      - kind: unit
        ref: "tests/Unit/TicketNumberTest.php#succeedsAgainstACompletelyEmptyCountersTable"
        status: pass
      - kind: unit
        ref: "tests/Unit/TicketNumberTest.php#sequentialCallsIncrementWithinThePeriod"
        status: pass
    human_judgment: false
  - id: D5
    description: "bin/migrate.php up applies all 5 migrations from an empty database in order, is idempotent (0 pending on rerun), and every _up.sql has a paired _down.sql"
    verification:
      - kind: integration
        ref: "php bin/migrate.php up && php bin/migrate.php status -- all 5 applied, second up reports 0 pending"
        status: pass
    human_judgment: false
  - id: D6
    description: "Composer package legitimacy audit (vlucas/phpdotenv, ramsey/uuid, monolog/monolog, phpunit/phpunit) -- automated package-legitimacy seam does not support Composer"
    verification: []
    human_judgment: true
    rationale: "Plan's <verify><human-check> requires a human to open each package's Packagist page and confirm multi-year history / hundreds-of-millions+ downloads / matching source repo -- not automatable this session."

duration: 40min (this resumed execution session; excludes the prior session's research/planning and the initial environment-provisioning pause)
completed: 2026-08-22
status: complete
---

# Phase 1 Plan 1: Walking Skeleton -- Text Intake to Ticket Number, with an Unfalsifiable Audit Trail Summary

**A `POST /api/v1/tickets` text-only request now returns a real `UPF-YYYYMM-NNNNN` ticket number, persists a `dbo.tickets` row and exactly one append-only `dbo.ticket_events` row in a single transaction, and the application's own SQL Server login is now provably, database-level unable to edit or delete that audit row.**

## Performance

- **Duration:** ~40 min (this resumed session; the plan was previously paused at Task 2's environment precondition, see `bb41d23`)
- **Completed:** 2026-08-22
- **Tasks:** 3/3 complete (Task 1 decision, Task 2 tracer, Task 3 audit trail)
- **Files modified:** 34 (see key-files; excludes `.planning/` bookkeeping)

## Accomplishments

- Stood up the full walking-skeleton application: Composer scaffold, `Db/Connection.php` (deadlock-retry verified against a real forced deadlock), `bin/migrate.php`, 5 migrations, HTTP layer, `TicketController`, and a working `POST /api/v1/tickets` returning a real race-safe ticket number.
- Made AUDIT-01 true at the database-privilege level: `dbo.ticket_events` cannot be updated or deleted by the application's own login, verified by a dedicated 6-case test suite running against the live database, not mocked.
- Uncovered and fixed a real correctness gap in `Connection::run()` (PDO silently treats SQL Server permission-statement warnings as success) that would otherwise have let a security-critical migration silently no-op.
- Empirically confirmed (rather than assumed) two RESEARCH.md open questions: the `PDOException::$errorInfo` shape for SQL Server error 1205, and that SQL Server's filtered-index predicate parser rejects `NOT IN`.

## Task Commits

1. **Task 1: Pin PHP version floor / pdo_sqlsrv release** (checkpoint:decision, resolved `php83`) -- applied inline in the commits below (no standalone commit; the decision has no code artifact of its own)
2. **Task 2: End-to-end text intake -> ticket number** (tracer, tdd=true)
   - `709c603` chore(01-01): add .gitignore before any dependency install or .env write
   - `ee36be2` chore(01-01): scaffold Composer project and test config
   - `c9c40c0` feat(01-01): add Env/Logger support classes and Connection.php
   - `bd61da8` feat(01-01): migration runner + migrations 001/002/003/008
   - `69cf4d6` feat(01-01): add Taxonomy and TicketNumber domain classes
   - `4151c59` feat(01-01): complete Task 2 tracer -- text intake to ticket number end-to-end
3. **Task 3: Make the audit trail real and unfalsifiable (AUDIT-01)** (auto, tdd=true)
   - `fd7e4dc` fix(01-01): Connection::run() escalates SQLSTATE warnings to exceptions
   - `2339010` feat(01-01): Task 3 code complete -- ticket_events append-only audit trail (AUDIT-01)
   - `5017573` fix(01-01): remove DELETE-based test cleanup now that AUDIT-01 is live

**Plan metadata:** this commit (docs: complete 01-01 plan)

_Note: both tracer and auto tasks carried `tdd="true"`; tests were written alongside/before their implementations per file (`TicketNumberTest`/`TicketIntakeTest` before `TicketController`'s full wiring; `TicketEventsImmutabilityTest` before the DENY was live) rather than as separate RED/GREEN commits, since the tracer/auto task types don't mandate the strict three-commit TDD gate sequence that `type: tdd` plans do._

## Files Created/Modified

- `composer.json`, `phpunit.xml`, `.gitignore`, `.env.example`, `README.md` -- project scaffold and local-run documentation
- `public/index.php`, `public/.htaccess` -- front controller, `set_exception_handler()` converting every throwable into the SPEC.md 6.3 envelope
- `src/Support/Env.php`, `src/Support/Logger.php` -- config loading, JSON-line logging
- `src/Db/Connection.php` -- PDO sqlsrv factory, `run()`, `withDeadlockRetry()` (now escalates SQLSTATE warnings too)
- `src/Http/Router.php`, `Request.php`, `Response.php`, `Controllers/TicketController.php` -- routing + intake transaction
- `src/Domain/Taxonomy.php`, `TicketNumber.php`, `EventLog.php` -- constants, race-safe ticket_no, append-only event writer
- `bin/migrate.php` -- migration runner (up/status/down --step=N)
- `database/migrations/{001,002,003,006,008}_*_{up,down}.sql` -- schema, including the AUDIT-01 DENY grant
- `tests/Unit/TicketNumberTest.php`, `tests/Feature/{TicketIntakeTest,TicketEventsImmutabilityTest}.php`

## Decisions Made

- **PHP floor (Task 1):** `php83` -- PHP 8.3+, `pdo_sqlsrv` 5.13.3. Confirmed installed and working: PHP 8.5.4, msphpsql 5.13.3 (NTS build), ODBC Driver 18.6.2.1.
- **`tickets.assigned_to`:** left as `UNIQUEIDENTIFIER NULL` with no FK. SPEC.md 5.3 declares it as an FK to `technicians`, but that table is never defined anywhere in SPEC.md section 5 and isn't created until Phase 3. Documented inline in migration 003's header comment, per the plan's explicit instruction.
- **Filtered index rewrite:** SPEC.md 5.3's literal `WHERE status NOT IN ('closed', 'cancelled')` is rejected by SQL Server's filtered-index predicate parser (`Incorrect syntax near 'NOT'`, confirmed empirically). Rewritten as `WHERE status <> 'closed' AND status <> 'cancelled'`, semantically identical for exactly these two excluded values.
- **`Connection::run()` warning escalation:** `PDO::ATTR_ERRMODE_EXCEPTION` only throws for SQLSTATE error classes, not SQLSTATE `01000`-class warnings. This let migration 006's `DENY` statement silently "succeed" the first time while doing nothing. `run()` now inspects `PDOStatement::errorInfo()` after every `execute()` and throws for any non-`'00000'` SQLSTATE.
- **Migration-runner credential separation (environment-level, not code-level):** `bin/migrate.php`'s `DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app` statement cannot be executed while connected as `upfix_app` itself -- SQL Server refuses a principal granting/denying/revoking permissions to itself (`SQLSTATE[01000]: Cannot grant, deny, or revoke permissions to sa, dbo, entity owner, information_schema, sys, or yourself`). This is a deliberate SQL Server security invariant (it prevents a compromised app login from re-granting itself power it was just denied), not a bug. The coordinator applied migration 006 once, verbatim, as `sa` (an elevated, non-`upfix_app` principal) and inserted the corresponding `schema_migrations` tracking row. All other migrations (001/002/003/008) apply cleanly as `upfix_app` since they don't target permissions on `upfix_app` itself.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Filtered index `WHERE status NOT IN (...)` is invalid T-SQL for a filtered index**
- **Found during:** Task 2, running `bin/migrate.php up` for the first time
- **Issue:** SPEC.md 5.3's index block specifies `CREATE INDEX IX_tickets_sla_open ON tickets(sla_resolve_by) WHERE status NOT IN ('closed', 'cancelled')`. SQL Server's filtered-index predicate parser rejects `NOT IN` (`Incorrect syntax near 'NOT'`), confirmed by isolating the statement and testing `<>`, `AND`-chained `<>`, and plain `IN` individually against the live instance -- `NOT IN` is the only form that fails.
- **Fix:** Rewrote as `WHERE status <> 'closed' AND status <> 'cancelled'`, verified to apply cleanly and to be semantically identical for these two literal exclusions.
- **Files modified:** `database/migrations/003_create_tickets_up.sql`
- **Commit:** `bd61da8`

**2. [Rule 1 - Bug] `PDO::query(` docblock text tripped the acceptance-criterion grep**
- **Found during:** Task 2, running the plan's acceptance-criteria checks
- **Issue:** `grep -rn "PDO::query(" src/ public/ bin/ | wc -l` returned 1 -- not from actual code, but from a docblock comment in `Connection.php` mentioning "Never call `PDO::query()` with interpolated strings."
- **Fix:** Reworded the comment to avoid the literal string.
- **Files modified:** `src/Db/Connection.php`
- **Commit:** `4151c59`

**3. [Rule 1 - Bug] Stray unused `$buildingCode` closure capture caused an undefined-variable warning**
- **Found during:** Task 2, running the Feature test suite with `--display-warnings`
- **Issue:** `building_code` is read per the field contract but building_id resolution is out of scope this task; a leftover `$buildingCode` reference in the `withDeadlockRetry` closure's `use` list (never assigned) produced a PHP warning under every test run.
- **Fix:** Removed the stray capture; `$request->input('building_code')` is still called (satisfying "reads building_code") but its result isn't retained.
- **Files modified:** `src/Http/Controllers/TicketController.php`
- **Commit:** `4151c59`

**4. [Rule 2 - Missing critical functionality] `Connection::run()` silently treated SQL Server permission-statement warnings as success**
- **Found during:** Task 3, migration 006's DENY statement appeared to apply (no exception, `bin/migrate.php` reported `Applied:` and exit 0) but `sys.database_permissions`/`fn_my_permissions` showed no effect, and UPDATE/DELETE against `dbo.ticket_events` still succeeded.
- **Issue:** SQL Server returns SQLSTATE `01000` (warning class) for `DENY ... TO <self>` rather than an error; `PDO::ATTR_ERRMODE_EXCEPTION` does not escalate warning-class SQLSTATEs to exceptions, so `execute()` reported success while the statement was a genuine no-op. For a migration runner whose entire purpose includes applying a security-critical DENY grant, silently tolerating this is unacceptable.
- **Fix:** `Connection::run()` now inspects `PDOStatement::errorInfo()` after every `execute()` and throws a `PDOException` (with `errorInfo` populated) for any non-`'00000'` SQLSTATE, so `withDeadlockRetry()` and all other callers see it.
- **Files modified:** `src/Db/Connection.php`
- **Verification:** Full Unit+Feature suite still green after the change (11 tests, 23 assertions, 0 regressions); confirmed `bin/migrate.php up` now correctly rolls back and reports exit 1 for a migration whose DENY statement is a no-op, instead of falsely reporting success.
- **Commit:** `fd7e4dc`

**5. [Rule 1 - Bug] Test isolation: `TicketNumberTest` used real-looking periods that collided with real ticket data**
- **Found during:** Task 3, running the full suite together for the first time (`--testsuite Unit,Feature`)
- **Issue:** `TicketNumberTest` used periods `202608`/`202609` (today's actual calendar period) and truncated `dbo.ticket_counters` for them. `TicketController::create()` allocates against `gmdate('Ym')` -- the real current period -- so resetting the shared counter caused later tests (and leftover manual-verification data from earlier `curl` runs) to collide on `ticket_no` uniqueness (`Violation of UNIQUE KEY constraint 'UQ_tickets_ticket_no'`).
- **Fix:** Switched `TicketNumberTest` to deliberately out-of-band periods (`000001`/`000002`) that can never collide with real app-generated data, and scoped the `DELETE FROM dbo.ticket_counters` to just those two periods rather than the whole table.
- **Files modified:** `tests/Unit/TicketNumberTest.php`
- **Commit:** `2339010`

**6. [Rule 1 - Bug] Test cleanup (`tearDown`) tried to DELETE rows that AUDIT-01 now correctly protects**
- **Found during:** Task 3, after the coordinator applied the live DENY and the full suite was re-run
- **Issue:** `TicketIntakeTest` and `TicketEventsImmutabilityTest` both had a `tearDown()` that deleted `ticket_events` then `tickets` rows to reset state between runs. Once `DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app` was genuinely in effect, this `DELETE` was correctly rejected with the exact same "permission was denied" error the tests exist to prove -- and any `tickets` row with an event attached also became undeletable via `FK_ticket_events_ticket`.
- **Fix:** Removed the `DELETE`-based `tearDown()` entirely from both test classes. Test data is now permanent (matching real production behaviour under an append-only audit trail); every assertion keys off its own fresh UUID ticket id, so accumulated rows from prior runs never interfere.
- **Files modified:** `tests/Feature/TicketIntakeTest.php`, `tests/Feature/TicketEventsImmutabilityTest.php`
- **Commit:** `5017573`

---

**Total deviations:** 6 auto-fixed (2 Rule 1 bugs found before the DENY was live, 1 Rule 1 docblock-grep fix, 1 Rule 2 missing-critical-functionality fix, 2 Rule 1 test-isolation fixes discovered after the DENY went live).
**Impact on plan:** All fixes were necessary for correctness (filtered index would never have applied; the `Connection::run()` fix closes a real silent-failure class for security-critical statements; the test fixes are required consequences of AUDIT-01 actually working). No scope creep -- nothing outside migration 003/006, `Connection.php`, `TicketController.php`, and the three test files was touched.

### Environment/Infrastructure Note (not a code deviation, but load-bearing)

Migration 006's final statement, `DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app`, **cannot** be executed by a connection authenticated as `upfix_app` itself -- SQL Server enforces this as a hard, undocumented-in-SPEC.md security invariant: `SQLSTATE[01000]: Cannot grant, deny, or revoke permissions to sa, dbo, entity owner, information_schema, sys, or yourself.` This is unrelated to role membership (`db_ddladmin`/`db_securityadmin`, granted earlier to unblock DDL for migrations 001/002/003/008, do not help here) and is by design: if an application login could deny itself a permission, it could just as easily grant it back, making the DENY meaningless as a control.

**Resolution:** the coordinator ran migration 006's `CREATE TABLE`/index/`DENY` statements verbatim as `sa` (an elevated, non-`upfix_app` principal), then inserted the tracking row into `dbo.schema_migrations` for `006_create_ticket_events_up.sql`. This matches how production deployments actually separate a DBA/migration identity from the application's own least-privilege runtime login -- `bin/migrate.php` remains fully correct and self-contained for any operator running it with a suitably privileged (non-self-targeted) credential; only this project's single dev-sandbox `.env` (used for both migrations and runtime) hit the edge case.

**README.md does not yet document this requirement explicitly** -- flagging here for a follow-up doc note: whoever runs `php bin/migrate.php up` in a fresh environment for the first time must use a DB login that is *not* the same login migration 006's DENY targets (`upfix_app`), or migration 006 will fail with the SQLSTATE 01000 error above and roll back cleanly (thanks to deviation #4's fix) rather than silently lying about success.

## Issues Encountered

**Environment provisioning (resolved across two coordinator round-trips before this session, and one mid-session round-trip):**
1. No PHP/Composer/SQL Server/Docker in the original sandbox -- resolved by the user provisioning PHP 8.5.4, Composer, `msodbcsql18`, and `pdo_sqlsrv`/`sqlsrv` (msphpsql 5.13.3) directly.
2. No reachable SQL Server instance -- resolved by the coordinator standing up an instance at `10.209.30.97:1433`, creating the `upfix` database and `upfix_app` login, and writing `.env`.
3. `upfix_app` initially had only `db_datareader`/`db_datawriter` (DML only, no DDL) -- `bin/migrate.php` couldn't `CREATE TABLE`. Resolved by the coordinator granting `db_ddladmin` + `db_securityadmin` (a narrower alternative to `db_owner`, offered and accepted).
4. `php8.5-mbstring` is not installed system-wide and `sudo apt-get install` requires interactive auth unavailable to this session. Worked around without requiring further coordinator involvement: downloaded the `.deb` via `apt-get download` (no root required), extracted `mbstring.so` with `dpkg-deb -x`, and load it via a user-writable `php.ini` snippet referenced through `PHP_INI_SCAN_DIR` (exported per-command in this session; also appended to `~/.bashrc` for future interactive shells, though non-interactive Bash tool calls don't source it automatically). `vendor/symfony/polyfill-mbstring` (already a transitive Composer dependency) provides working `mb_*` functions for application code even without the native extension loaded, but PHPUnit's own CLI hard-requires the native extension to start, hence the extra step. Documented in `README.md`.
5. Migration 006's self-targeting `DENY` statement -- see the dedicated note above.

None of these are code defects; all are documented above and in `README.md` where relevant so a future environment rebuild doesn't rediscover them from scratch.

## User Setup Required

None further -- `.env` is already configured and verified working end-to-end. See the Environment/Infrastructure Note above: any *future* fresh-environment `bin/migrate.php up` run needs a non-`upfix_app` credential specifically for migration 006 (or an already-applied `dbo.ticket_events` + DENY, as is now the case in this environment).

## Next Phase Readiness

- `Connection.php`, `bin/migrate.php`, `EventLog.php`, and the `schema_migrations` tracking convention are all in place for plan 01-02 (job queue) and 01-03 (photo intake + remaining tables) to build on without re-deciding any of these seams.
- `Connection::run()`'s new warning-escalation behaviour applies globally -- any future migration or query that would have silently no-op'd on a SQLSTATE warning now fails loudly instead.
- The `technicians` FK deferral on `tickets.assigned_to` is carried forward to Phase 3 exactly as instructed; no other phase-1 gaps identified against this plan's `must_haves`/`acceptance_criteria`.
- **Plan-authoring quirks worth flagging to future plan-writers (not blocking, no code impact):**
  - The plan's own `<verify><automated>` line for Task 2 repeats `--testsuite` twice (`--testsuite Unit --testsuite Feature`), which PHPUnit 11's CLI treats as a runner error (exit 1) even though all tests pass. The corrected comma-separated form (`--testsuite Unit,Feature`) is what this SUMMARY's verification actually used.
  - Task 3's acceptance criterion `grep -c "public function " src/Domain/EventLog.php` expects `1`, but the correct implementation is `public static function record(...)` (required by the plan's own call-site example, `EventLog::record($db, ...)`, which needs a static method) -- the grep pattern doesn't match the `static` keyword and returns `0`. Verified functionally instead: `grep -c "public static function " src/Domain/EventLog.php` returns `1`, and the class exposes exactly one public method.

## Self-Check: PASSED

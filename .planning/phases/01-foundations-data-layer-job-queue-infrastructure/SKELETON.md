# Walking Skeleton — UP-Fix (Smart Maintenance Request System)

**Phase:** 1
**Generated:** 2026-08-20

## Capability Proven End-to-End

A fault report submitted to `POST /api/v1/tickets` (text and/or photos) is persisted with a permanent
`UPF-YYYYMM-NNNNN` ticket number and an append-only audit event, its follow-up job is enqueued, and a
separate supervised worker process claims that job atomically and completes it — all reproducible from
an empty database with `php bin/migrate.php up`.

## Architectural Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Runtime | PHP 8.2 or 8.3 — pinned by plan 01-01 Task 1 | SPEC.md 8.1 locks PHP; the exact floor is gated by `pdo_sqlsrv` (5.13.3 needs 8.3+, 5.12.0 is the last 8.2 build). Resolved as a checkpoint, not assumed. |
| Framework | None — hand-rolled front controller + router (`public/index.php` → `src/Http/Router.php`) | SPEC.md 8.1 permits hand-rolled MVC; a framework adds operational surface university IT would have to maintain, and this API has ~15 endpoints total across all five phases. |
| Data layer | Microsoft SQL Server 2019+ via `pdo_sqlsrv` + ODBC Driver 18, accessed only through `src/Db/Connection.php` | SPEC.md 8.1; the single `Connection::run(sql, params)` seam makes prepared statements the path of least resistance and centralises deadlock (error 1205) retry. |
| Schema management | Forward `.sql` migrations under `database/migrations/NNN_name_up.sql` with paired `_down.sql`, applied by `bin/migrate.php` against a bootstrap-created `schema_migrations` table (D-01) | No production data exists yet; down scripts are dev-time schema-error recovery reachable only via an explicit `down --step=N` command, never from `up`. |
| Identifiers | `UNIQUEIDENTIFIER DEFAULT NEWSEQUENTIALID()` primary keys; human-facing `ticket_no` allocated from `ticket_counters` via one atomic MERGE with `HOLDLOCK` | SPEC.md 5.0 and 5.9. The MERGE form closes SPEC.md's unwritten "if no row exists, INSERT starting at 1" branch so the first ticket of a new month cannot fail or race. |
| Audit trail | `ticket_events` append-only, enforced by `DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app` issued inside migration 006; all writes go through `src/Domain/EventLog.php` | AUDIT-01. Database privilege, not application convention — a buggy or compromised application code path still cannot rewrite history. SQL Server 2022+ Ledger tables were rejected: SPEC.md targets 2019+. |
| Async work | `job_queue` table + PHP CLI worker, claimed with one atomic `UPDATE TOP (1) ... WITH (ROWLOCK, READPAST, UPDLOCK) ... OUTPUT` | Project constraint forbids Redis/Elasticsearch/vector DB (PROJECT.md). The database transaction is the only coordination primitive available across separate OS processes. |
| Handler contract | `HandlerInterface` (`alreadyProcessed()` + `handle()`) behind a shared `Dispatcher` seam | Stale-lock recovery makes delivery at-least-once; idempotency is per-job-type domain logic, so the seam is an interface every Phase 2+ handler inherits rather than one generic idempotency table. |
| Auth | None this phase — the intake API is internal-only | ROADMAP scopes LINE, dashboard, and LIFF to Phase 4; SPEC.md 10.2's JWT + five-role RBAC ships with them. This endpoint must not be publicly exposed before then. |
| File storage | Server filesystem under `STORAGE_PATH`, outside the webroot; originals at `media/_raw/{uuid}.{ext}` | SPEC.md 10.2 and 5.4. Filenames are regenerated UUIDs; the client-supplied name is discarded at the boundary. |
| Deployment target | Linux + Nginx/PHP-FPM, worker under a single systemd unit, scheduler under cron (D-03, D-04) | Lower operational friction for a PHP CLI worker, and SPEC.md 8.5's own ordering. The Windows/IIS + Task Scheduler path is deferred pending SPEC.md 16 Q7; `bin/worker.php` is platform-agnostic so only the supervisor wrapper changes. |
| Directory layout | SPEC.md 8.4 verbatim: `public/`, `src/{Http,Domain,Queue,Support,Db}`, `bin/`, `database/{migrations,seed}`, `storage/`, `tests/{Unit,Feature}` | A pre-existing authoritative layout every later phase's files already have a home in. |
| Frontend | None this phase | No UI exists until Phase 4; the interactive surface proven here is the HTTP API exercised by `curl` and the Feature test suite. |

## Stack Touched in Phase 1

- [ ] Project scaffold — `composer.json` (PSR-4 `UpFix\`), `phpunit.xml`, `.env.example`, `.gitignore` (plan 01-01 Task 2)
- [ ] Routing — `public/index.php` front controller + `src/Http/Router.php`, with `POST /api/v1/tickets` and `GET /api/v1/healthz` (plans 01-01, 01-03)
- [ ] Database — real write (`tickets`, `ticket_events`, `ticket_media`, `job_queue`) and real read (`ticket_counters` allocation, `buildings` lookup, queue depth) (plans 01-01, 01-02, 01-03)
- [ ] Interactive element wired to the API — `curl -X POST -F 'text=…' -F 'images[]=@photo.jpg'` returning a real ticket number; no browser UI exists until Phase 4
- [ ] Deployment — documented local full-stack run in `README.md` (`composer install` → `php bin/migrate.php up` → `php bin/migrate.php seed` → `php -S 127.0.0.1:8080 -t public` → `php bin/worker.php`), plus the systemd unit and cron entry under `deploy/` for the target host

## Out of Scope (Deferred to Later Slices)

- AI classification, evidence citation, hazard escalation, PII redaction, duplicate detection — Phase 2. `ai_classifications` exists as schema with no writer; `redact_media` and `classify_ticket` jobs are enqueued but dispatch to a no-op stub.
- Deterministic routing, the confidence-tiered triage queue, and the Thai-business-day SLA engine — Phase 3. `holidays` exists as schema with no consumer; `technicians` does not exist yet, which is why `tickets.assigned_to` carries no foreign key.
- LINE OA intake, push notifications, the staff dashboard, the technician LIFF view, JWT/RBAC authentication, hashed LINE identifiers, and the authorised media-serving endpoint — Phase 4. `idempotency_keys` and `rate_limits` exist as schema with no enforcement.
- Legacy Smart Services import and the repeat-repair / AI-cost analytics — Phase 5.
- Multi-worker horizontal scale-out (D-04), the Windows/IIS supervisor path (D-03), and the authoritative University of Phayao building/asset reference list (D-02, SPEC.md 16 Q3) — all deferred by explicit decision, all additive when they arrive.
- The 24-hour `_raw` purge: there is no redacted copy to purge toward until Phase 2 builds the redaction pass.

## Subsequent Slice Plan

Each later phase adds one vertical slice on top of this skeleton without altering its architectural
decisions above:

- Phase 2: a submitted ticket comes back classified with evidence, hazards force-escalated to P1, PII redacted, and near-duplicates flagged — real handlers replacing `NoopHandler` behind the same `HandlerInterface`.
- Phase 3: a classified ticket is auto-assigned to the right team, low-confidence cases go to human triage, and an SLA clock runs against Thai business days.
- Phase 4: a reporter submits from LINE, watches status change, and a technician closes the job from LIFF with an "after" photo.
- Phase 5: management sees repeat-repair cost per asset and monthly AI spend, grounded in imported legacy history.

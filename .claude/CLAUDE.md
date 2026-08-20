<!-- GSD:project-start source:PROJECT.md -->

## Project

**UP-Fix — Smart Maintenance Request System**

A maintenance request system for the Division of Buildings and Grounds at University of Phayao. A reporter (student, faculty, or staff) takes a photo and types a short sentence via LINE OA — AI classifies the work type, assesses urgency, identifies the location, detects duplicates, predicts required materials, and routes the job to the correct technician team. Staff get a dashboard for triage, assignment, and SLA tracking; technicians get a LIFF mobile view; management gets repeat-repair and cost analytics.

**Core Value:** A reporter who knows nothing about trades or routing can report a fault with just a photo and a sentence, and the system reliably gets it to the right team fast — with hazardous faults (P1) never missed. AI proposes; humans always retain override authority.

### Constraints

- **Tech stack**: PHP 8.2+ (API), Microsoft SQL Server 2019+ (`pdo_sqlsrv`), Vanilla JS/HTML/CSS frontend (no build step) — matches university IT's existing environment and expertise
- **AI**: Anthropic Claude API via Guzzle (no official PHP SDK) — Sonnet for full analysis, Haiku for screening/duplicate adjudication, cost target ≤ THB 0.60/ticket
- **No infra add-ons**: no Redis, no Elasticsearch, no vector database — SQL Server alone (`job_queue` table replaces a message queue) to minimize operational burden on university IT
- **Messaging**: LINE Messaging API + LIFF only — zero install for reporters and technicians
- **Compliance**: PDPA — automatic PII redaction, hashed identifiers, defined retention (images 2yr, tickets 5yr), consent on first LINE link
- **Async AI**: all AI work happens in a background worker; the API must respond immediately and intake must never fail because AI is down
- **Auditability**: all rule-based decisions (SLA, routing, cost) are plain PHP, not LLM — auditable and unit-testable; `ticket_events` is append-only at the DB level

<!-- GSD:project-end -->

<!-- GSD:stack-start source:research/STACK.md -->

## Technology Stack

## Recommended Stack

### Core Technologies

| Technology | Version | Purpose | Why Recommended |
|------------|---------|---------|-----------------|
| PHP | 8.2 or 8.3 (see pitfall below) | API + CLI worker runtime | Locked by project. Pin the *floor* at 8.2 as SPEC.md states, but see "What NOT to Use" — the newest `pdo_sqlsrv` driver wants 8.3+. |
| `pdo_sqlsrv` (Microsoft Drivers for PHP for SQL Server) | **5.13.0** (first GA release in 2+ years as of 2026; requires PHP 8.3+, adds 8.4/8.5 support) | SQL Server connectivity | Official Microsoft driver; only supported way to hit SQL Server from PHP with prepared statements and full `NVARCHAR`/Unicode support. |
| Microsoft ODBC Driver for SQL Server | 18 (17 also supported) | Low-level transport layer `pdo_sqlsrv` sits on | Required system dependency, not a Composer package — must be installed at the OS level before `pdo_sqlsrv` will connect. Confirm with university IT it can be installed before development starts (SPEC.md §16, open question 8). |
| guzzlehttp/guzzle | **^7.10** (not the new 8.0.x) | HTTP client for Anthropic + LINE REST calls | Guzzle 8.0.x is now the documented "latest," but it's a very recent major bump (PHP `>=7.4,<8.6`) with an ecosystem of PSR-18/middleware packages that hasn't fully caught up yet. For a university system with no dedicated ops team to firefight a major-version regression, **7.10+ is the safer pin** — it's still actively maintained and gets security patches. Revisit 8.x once it's been stable for 6+ months. |
| Chart.js | **4.5.x**, vendored locally (`assets/vendor/chart.min.js`, UMD build) | Dashboard charts | Matches SPEC.md exactly. Use `dist/chart.umd.min.js`, not the ESM build — a plain `<script>` tag + `new Chart(ctx, config)` needs no import machinery, which fits the no-build-step constraint. **Never load from a CDN** (SPEC.md is explicit about this, and it's correct: CDN outage = broken dashboard, and it's an unnecessary third-party dependency for a system handling PII-adjacent data). |
| LIFF SDK | **v2.29.x** (rolling; use the CDN *edge* path, not a pinned patch) | Technician mobile UI inside LINE's in-app browser | `https://static.line-scdn.net/liff/edge/2/sdk.js` always serves the latest v2 features/fixes with zero maintenance; a fixed-path URL (`.../edge/versions/2.29.1/sdk.js`) trades that for reproducibility. For a small internal tool, the edge path is fine — LINE keeps v2 backward compatible. |

### Supporting Libraries

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `opis/json-schema` | **^2.6** (SPEC.md pins `^2.3` — widen it) | Server-side validation of the AI's JSON output (§4.3) | Every AI call, no exceptions — "the model used tool-use/structured output" is never sufficient on its own (see Pitfalls). |
| `firebase/php-jwt` | **^7.1** (SPEC.md pins `^6.10` — widen it) | JWT issuance/verification for the API | v7 is source-compatible with v6 for `JWT::encode`/`JWT::decode`; upgrade for current algorithm-confusion mitigations. |
| `ramsey/uuid` | ^4.7+ | Generating `UNIQUEIDENTIFIER`-compatible GUIDs in PHP where `NEWSEQUENTIALID()` isn't used (e.g., media filenames) | Filename regeneration (§10.2), idempotency keys. |
| `monolog/monolog` | **^3.10** (SPEC.md's `^3.5` is fine, just widen) | Structured JSON logging | Monolog 4 is still unreleased/planned as of Aug 2026 — stay on 3.x, no need to plan a migration yet. |
| `vlucas/phpdotenv` | ^5.6 | `.env` loading | Standard choice, no better alternative for this constraint set. |
| `linecorp/line-bot-sdk` | latest (PHP 8.2+) | LINE Messaging API client — signature verification, webhook parsing, message building, content download | **Not in SPEC.md's composer.json — recommend adding it.** It's the official SDK, requires PHP 8.2+ (matches the floor exactly), and removes a security-sensitive piece of hand-rolled code (HMAC signature verification) from `src/Integration/LineClient.php`. Doesn't conflict with "no build step" — it's a backend Composer package, not a frontend framework. If the team prefers to keep `LineClient.php` fully hand-rolled for auditability, that's defensible too — but at minimum, cross-check the hand-rolled signature verification against the SDK's implementation. |
| `anthropic-ai/sdk` (official Anthropic PHP SDK) | current (2026) | Anthropic API client | **Exists now and didn't when SPEC.md's "no official PHP SDK" line was written.** The project has locked in raw Guzzle — respect that decision — but flag to the team that this premise is now stale, in case they want to revisit later (structured-output helpers and typed exceptions would reduce `AnthropicClient.php` boilerplate). Not a recommendation to switch mid-flight. |

### Development Tools

| Tool | Purpose | Notes |
|------|---------|-------|
| PHPUnit | Test runner | **Use ^11.0, not ^10.5 (SPEC.md's pin) and not 13.x.** See Pitfalls — version/PHP-floor mismatch. |
| Imagick | PII redaction (pixelation), HEIC→JPEG conversion, EXIF auto-orient | Confirm the `imagick` PHP extension *and* the underlying ImageMagick HEIC delegate (`libheif`) are installable on the target server — this is a compiled system dependency, not just a PECL extension (SPEC.md §16, open question 8). GD is listed as fallback but **cannot decode HEIC** — if Imagick/libheif isn't available, iPhone photos will fail outright, not degrade gracefully. |

## Installation

# Core

# Recommended addition (not in SPEC.md's composer.json)

# Dev dependencies

# System-level (NOT Composer — confirm with university IT before development starts)

# - Microsoft ODBC Driver 18 for SQL Server

# - php-pdo_sqlsrv (PECL or Microsoft's prebuilt SPL, matching PHP's exact minor version and thread-safety build)

# - php-imagick + ImageMagick with HEIF/HEIC delegate (libheif)

# Frontend — vendored, not npm-installed at runtime

# Download Chart.js 4.5.x UMD build once, commit dist/chart.umd.min.js to

# public/assets/vendor/chart.min.js. Do not add a package.json / node_modules

# to the production host.

## Alternatives Considered

| Recommended | Alternative | When to Use Alternative |
|-------------|-------------|-------------------------|
| `pdo_sqlsrv` polling worker for `job_queue` | Redis + a queue library (BullMQ-equivalent) | Only if university IT later approves a Redis instance to operate — not applicable here per explicit "no infra add-ons" constraint. |
| Guzzle ^7.10 | Guzzle ^8.0 | Once the PSR ecosystem (event-loop/middleware packages) has visibly caught up to 8.x — check back in ~6 months from GA. |
| Raw Guzzle calls to `api.anthropic.com/v1/messages` | Official `anthropic-ai/sdk` | If the team decides SPEC.md's SDK constraint is worth revisiting — the SDK reduces boilerplate (typed exceptions, `StructuredOutputModel`) at the cost of one more dependency to track. |
| Hand-rolled `LineClient.php` over Guzzle | `linecorp/line-bot-sdk` | Team strongly prefers zero third-party code touching webhook signature verification and is willing to maintain that code themselves long-term. |
| CDN edge path for LIFF SDK | Pinned CDN fixed-path (`.../edge/versions/2.29.1/sdk.js`) | If the team wants LIFF version changes to require an explicit code change (more reproducible, more maintenance). |

## What NOT to Use

| Avoid | Why | Use Instead |
|-------|-----|--------------|
| PHPUnit 13.x with a PHP 8.2 deployment target | PHPUnit 13 **requires PHP 8.4+** (checked: `phpunit/phpunit` composer constraint). If the university server stays on PHP 8.2/8.3, `composer require --dev phpunit/phpunit:^13` will fail to install or silently downgrade. | PHPUnit **^11.0**, which requires PHP 8.2+ and is still receiving fixes into 2027+. Re-evaluate PHPUnit 13 only if/when the deployment PHP floor moves to 8.4. |
| `claude-sonnet-4` / `claude-haiku-4` as literal model ID strings (as written in SPEC.md §8.3 `.env.example`) | These are **not valid current model IDs**. As of Aug 2026 the real IDs are `claude-sonnet-5`, `claude-haiku-4-5`, `claude-opus-5` — no bare `-4` suffix exists for Sonnet/Haiku. Shipping the placeholder strings verbatim will 404/400 against the API on day one. | `AI_MODEL_PRIMARY=claude-sonnet-5`, `AI_MODEL_FAST=claude-haiku-4-5`. Confirm current IDs again at implementation time via `GET /v1/models` — these strings do change. |
| GD as the sole fallback for HEIC | GD has no HEIC/HEIF decoder, full stop. An iPhone photo uploaded when Imagick/libheif is unavailable won't "degrade" — it will hard-fail. | Treat Imagick + the ImageMagick HEIF delegate as a **hard requirement**, not an optional enhancement; verify it's installable on the target server before committing to the HEIC-conversion edge case (§12.1). |
| CDN-hosted Chart.js or LIFF SDK loaded without a documented pinning strategy | SPEC.md already correctly forbids CDN Chart.js. For LIFF, an *unpinned* edge-path CDN script is standard LINE practice, but if a specific reproducible build matters more than always-latest, an unpinned CDN is a silent-drift risk. | Chart.js: vendor locally (already SPEC.md's plan). LIFF: CDN edge path is fine for this scale, just know it's not pinned. |
| Relying on SQL Server session state (e.g., `SET` options, temp tables) surviving across two "connections" from the same PHP process | `pdo_sqlsrv`/`sqlsrv` connection pooling is on by default; when a pooled connection is returned, SQL Server runs `sp_reset_connection`, wiping session-level state between logical reuses. | Don't assume anything set via `SET`/temp tables in one request/connection lifetime persists to the next pooled use — re-establish any required session state (e.g., `SET LANGUAGE`, isolation level) at the start of every unit of work if it matters. |
| Trusting the model's structured-output/tool-use guarantee as sufficient validation | Structured output constrains the *shape* Claude is likely to produce, but it is not a hard server-side guarantee — SPEC.md §4.3 already gets this right ("Validated with `opis/json-schema` on every call ... but still validate server-side regardless"). Skipping the second validation pass because "the API already enforces the schema" is a common and dangerous shortcut. | Always run `opis/json-schema` (or equivalent) against the parsed JSON before it touches the database, exactly as SPEC.md specifies — keep this as non-negotiable in the implementation. |

## Stack Patterns by Variant

- Do not install `pdo_sqlsrv` 5.13.0 — it requires 8.3+. Pin to the last `pdo_sqlsrv` release that supports 8.2 (check the release matrix at implementation time; the driver's PHP-version support windows are narrow and change every 1-2 releases).
- Stay on PHPUnit ^11.0 (supports 8.2+); do not consider PHPUnit 12/13.
- `pdo_sqlsrv` and the ODBC driver are both fully supported on Windows (this is in fact Microsoft's primary target platform for the driver) — no functional gap there.
- Imagick/HEIC support is the bigger risk on Windows: ImageMagick's HEIF delegate is more commonly prebuilt and easy to install on Linux distros; on Windows it may require manually sourcing a build with `libheif` compiled in. Verify before committing to the HEIC edge case.
- `bin/worker.php` and `bin/scheduler.php` run via Windows Task Scheduler (as SPEC.md §8.5 already plans) rather than systemd — same "exit after N jobs/minutes, let the supervisor restart" pattern applies, just with different plumbing.
- `SmartServicesImporter` cannot use a simple cross-database `JOIN`; it needs either a SQL Server Linked Server (if network-reachable) or a scheduled CSV/ETL import. This materially changes the shape of `src/Integration/SmartServicesImporter.php` and should be resolved before that component is planned in detail.

## Version Compatibility

| Package A | Compatible With | Notes |
|-----------|-----------------|-------|
| `pdo_sqlsrv` 5.13.0 | PHP 8.3, 8.4, 8.5 | **Not** PHP 8.2 — this is the single most important version-floor conflict in the stack; resolve it against SPEC.md's "PHP 8.2+" constraint before writing `src/Db/Connection.php`. |
| PHPUnit ^11.0 | PHP 8.2+ | Correct pairing for this project's stated floor. |
| PHPUnit ^13.0 | PHP 8.4+ only | Do not use unless the PHP floor also moves to 8.4. |
| Guzzle ^7.10 | PHP >=7.2.5 (practically any PHP 8.x) | No compatibility risk; safe default. |
| Guzzle ^8.0 | PHP >=7.4, <8.6 | Compatible with 8.2+, but see "Alternatives Considered" for why 7.x is still the recommended pin right now. |
| `linecorp/line-bot-sdk` | PHP 8.2+ | Matches project floor exactly, no conflict. |
| Monolog ^3.10 | PHP 8.1+ | No conflict. Monolog 4 not yet released as of Aug 2026 — nothing to plan around yet. |
| Chart.js 4.5.x UMD build | Any browser target (no bundler needed) | No PHP/Composer interaction; purely a static asset. |
| Anthropic `claude-haiku-4-5` | Multimodal (text + image) input | Confirmed via multiple independent sources — safe for SPEC.md's plan to use Haiku for the image-quality gate and screening (§9.4), which requires vision. Re-verify with `GET /v1/models/{id}` at implementation time since capability flags can change between now and build start. |

## Sources

- `pdo_sqlsrv` version/PHP-floor: WebSearch, cross-referenced against Microsoft Learn and the Microsoft Community Hub announcement of Drivers 5.13.0 — MEDIUM confidence
- `pdo_sqlsrv` encoding/pooling behavior: WebSearch against Microsoft Learn "Connection Pooling" and "How to: Send and Retrieve UTF-8 Data" docs — MEDIUM confidence
- SQL Server deadlock (1205) retry pattern: WebSearch, corroborated by MSSQLTips and Microsoft Learn's `MSSQLSERVER_1205` doc — MEDIUM confidence
- SQL Server table-queue claim pattern (`READPAST`/`UPDLOCK`/`ROWLOCK`): WebSearch against SQLServerCentral, MSSQLTips, and Erik Darling's queue-design series — MEDIUM confidence; matches SPEC.md §5.7's existing design exactly
- Anthropic current model IDs, pricing, Haiku 4.5 vision support, PHP SDK existence and shape, structured-output/tool-use API surface: **`claude-api` skill** (curated, maintained reference, cache-dated 2026-06-24 in-skill + live WebSearch cross-check) — HIGH confidence
- LINE webhook signature verification pattern: WebSearch against developers.line.biz official docs (`verify-webhook-signature`) — MEDIUM confidence; matches SPEC.md §7 exactly
- LIFF SDK current version and CDN paths: WebSearch against developers.line.biz LIFF release notes and overview docs — MEDIUM confidence
- Chart.js version and UMD/vendoring guidance: WebSearch against npm and chartjs.org official docs — MEDIUM confidence
- Composer package versions (guzzlehttp/guzzle, opis/json-schema, firebase/php-jwt, monolog/monolog, phpunit/phpunit): WebSearch + direct WebFetch of Packagist package pages — MEDIUM/LOW confidence (Packagist version numbers change frequently; re-verify at implementation time with `composer show -a <package>`)
- Imagick/HEIC/EXIF-orientation gotchas: WebSearch against php.net manual and ImageMagick GitHub issues — MEDIUM confidence
- PHP long-running CLI worker patterns (exit-after-N-jobs, memory ceilings): WebSearch, drawn from Laravel queue-worker operational guidance generalized to a hand-rolled worker — MEDIUM confidence; SPEC.md §8.5 already reflects this pattern correctly
- `linecorp/line-bot-sdk` existence and PHP floor: WebSearch against Packagist/GitHub — MEDIUM confidence

<!-- GSD:stack-end -->

<!-- GSD:conventions-start source:CONVENTIONS.md -->

## Conventions

Conventions not yet established. Will populate as patterns emerge during development.
<!-- GSD:conventions-end -->

<!-- GSD:architecture-start source:ARCHITECTURE.md -->

## Architecture

Architecture not yet mapped. Follow existing patterns found in the codebase.
<!-- GSD:architecture-end -->

<!-- GSD:skills-start source:skills/ -->

## Project Skills

No project skills found. Add skills to any of: `.claude/skills/`, `.agents/skills/`, `.cursor/skills/`, `.github/skills/`, or `.codex/skills/` with a `SKILL.md` index file.
<!-- GSD:skills-end -->

<!-- GSD:workflow-start source:GSD defaults -->

## GSD Workflow Enforcement

Before using Edit, Write, or other file-changing tools, start work through a GSD command so planning artifacts and execution context stay in sync.

Use these entry points:

- `/gsd-quick` for small fixes, doc updates, and ad-hoc tasks
- `/gsd-debug` for investigation and bug fixing
- `/gsd-execute-phase` for planned phase work

Do not make direct repo edits outside a GSD workflow unless the user explicitly asks to bypass it.
<!-- GSD:workflow-end -->

<!-- GSD:profile-start -->

## Developer Profile

> Profile not yet configured. Run `/gsd-profile-user` to generate your developer profile.
> This section is managed by `generate-claude-profile` -- do not edit manually.
<!-- GSD:profile-end -->

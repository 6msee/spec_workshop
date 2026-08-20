# Phase 1: Foundations, Data Layer & Job Queue Infrastructure - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-08-20
**Phase:** 1-Foundations, Data Layer & Job Queue Infrastructure
**Areas discussed:** Migrations, Reference Data Seeding, Deployment Platform Target, Job Queue Worker Concurrency

Run in `--auto` mode: no interactive AskUserQuestion calls. Each area was auto-resolved to the recommended option per `discuss-phase/modes/auto.md`, grounded in SPEC.md and research/ findings where available.

---

## Migrations

| Option | Description | Selected |
|--------|-------------|----------|
| Forward-only, no down scripts | Simplest; no rollback path even in dev | |
| Up/down pairs, down unused in prod this phase | Matches SPEC.md repo layout (`buildings_up.sql`); dev-time safety net | ✓ |
| Full migration framework (e.g. Phinx) | Adds a dependency SPEC.md doesn't call for | |

**Selected:** Up/down pairs, down unused in prod this phase
**Notes:** SPEC.md's own repo-layout sketch already shows `buildings_up.sql`, implying paired down scripts exist. No production data exists yet so this is a dev-time convenience, not a rollback requirement.

---

## Reference Data Seeding

| Option | Description | Selected |
|--------|-------------|----------|
| Block phase 1 until university's building list arrives | Blocks the whole phase on an external, slow-moving dependency | |
| Ship empty/placeholder seed, track completeness separately | Matches SPEC.md's explicit "assets can be empty" design and "never auto-create buildings" pitfall guard | ✓ |

**Selected:** Ship empty/placeholder seed, track completeness separately
**Notes:** SPEC.md §5.2 explicitly requires the system work correctly with `assets` empty. research/PITFALLS.md flags the building-code list as a blocking dependency for *eval trustworthiness* (Phase 2), not for Phase 1 engineering — so it's tracked as a carried-forward blocker in STATE.md rather than gating this phase.

---

## Deployment Platform Target

| Option | Description | Selected |
|--------|-------------|----------|
| Linux/systemd first, Windows/Task Scheduler deferred | Lower operational friction; PHP application code is platform-agnostic either way | ✓ |
| Windows/IIS + Task Scheduler first | Matches if university turns out to require Windows | |
| Write both configs now | Extra work before the platform question (SPEC.md §16 Q7) is answered | |

**Selected:** Linux/systemd first, Windows/Task Scheduler deferred
**Notes:** SPEC.md itself leaves this open ("choose what university IT supports", §16 Q7). Only the supervisor config differs between platforms — `bin/worker.php` itself is unaffected — so this choice is cheap to reverse once the university confirms.

---

## Job Queue Worker Concurrency

| Option | Description | Selected |
|--------|-------------|----------|
| Single worker instance | Simplest to operate/debug for foundational phase; sufficient for 500 tickets/day | ✓ |
| Templated multi-worker (`@service`) from day one | SPEC.md sketches this form but volume doesn't require it yet | |

**Selected:** Single worker instance
**Notes:** The atomic `UPDATE ... WITH (ROWLOCK, READPAST, UPDLOCK)` claim pattern (SPEC.md §5.7) already makes concurrent workers safe — scaling to N workers later is a deployment/config change, not a code change.

---

## Claude's Discretion

- Exact `.sql` migration file naming/numbering beyond SPEC.md's repo-layout sketch (001–007+); planner may add further migrations as schema needs dictate.
- Internal structure of `bin/migrate.php` (transaction-per-file vs transaction-per-batch).

## Deferred Ideas

- Multi-worker horizontal scaling — deferred until volume requires it.
- Windows/IIS deployment path — deferred until university confirms platform.
- Full buildings/assets reference data — deferred pending university's authoritative list (SPEC.md §16 Q3); carried-forward blocker in STATE.md.

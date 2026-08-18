# UP-Fix — Smart Maintenance Request System

## What This Is

A maintenance request system for the Division of Buildings and Grounds at University of Phayao. A reporter (student, faculty, or staff) takes a photo and types a short sentence via LINE OA — AI classifies the work type, assesses urgency, identifies the location, detects duplicates, predicts required materials, and routes the job to the correct technician team. Staff get a dashboard for triage, assignment, and SLA tracking; technicians get a LIFF mobile view; management gets repeat-repair and cost analytics.

## Core Value

A reporter who knows nothing about trades or routing can report a fault with just a photo and a sentence, and the system reliably gets it to the right team fast — with hazardous faults (P1) never missed. AI proposes; humans always retain override authority.

## Requirements

### Validated

(None yet — ship to validate)

### Active

- [ ] Reporter can submit a ticket via LINE OA with photo and/or text, receive immediate acknowledgement, and get an AI-analyzed classification (category, subcategory, priority, location, summary) within ~30–60s
- [ ] Safety hazards are force-escalated to P1 regardless of model confidence, with immediate supervisor notification
- [ ] Duplicate detection (SQL filter → trigram/Dice similarity → LLM adjudication for borderline cases) prevents redundant dispatch, with reversible merge/unmerge
- [ ] Low-confidence classifications (<0.75) route to a human triage queue instead of auto-assigning; officers can correct classifications with a recorded reason
- [ ] Job routing to technicians is deterministic PHP logic (skills, zone, workload, asset repair continuity) — not LLM-driven
- [ ] Reporter can track ticket status end-to-end via LINE push notifications (assigned → in_progress → completed) and give satisfaction feedback
- [ ] Technician can view job details (photo, symptoms, asset history) via LIFF and close a job with a required "after" photo (enforced for P1/P2)
- [ ] Manager/director dashboard: open jobs, SLA breaches, workload per team, repeat-repair analysis with accumulated cost, monthly AI cost report
- [ ] SLA engine (P1–P4) computed against Thai business days/holidays, with on-hold time excluded from the clock
- [ ] Full audit trail (`ticket_events`, append-only) for every state transition and reclassification
- [ ] PII protection: automatic face/license-plate redaction on stored images, hashed LINE user IDs, raw images purged within 24h
- [ ] Fairness enforcement: identical symptoms yield identical priority regardless of reporter seniority or building (tested, AC-10)
- [ ] Legacy Smart Services history import for repeat-repair analytics baseline

### Out of Scope

- Procurement / disbursement workflows — integration point only, not owned by this system
- Individual technician performance evaluation — all analytics aggregated at team level only, enforced in code
- IoT sensors / automated fault detection — future phase
- Room or vehicle booking — existing systems already cover this
- Fully autonomous AI decisions — AI proposes, humans approve/override, always
- Native mobile app — LINE OA + LIFF + web dashboard only
- Any JS framework requiring a build step (React/Vue/Next) — plain HTML/CSS/JS only, no Node on production host

## Context

This replaces a maintenance-reporting process currently scattered across phone calls, personal LINE chats, paper forms, and walk-ins — causing misrouted jobs, technicians arriving without correct parts, no urgency triage, duplicate work orders, and no structured data for budget justification.

Full technical specification (architecture, data model, API, AI schema, routing rules, security, edge cases, acceptance criteria) lives in `SPEC.md` at the project root — treat it as the authoritative source for implementation detail; this PROJECT.md is the living summary. Spec version 2.0, dated 2026-08-18.

A labelled test dataset (≥300 real historical records, 70/15/15 split) is required for model quality evaluation before AI classification can be trusted in production (§11 of SPEC.md).

Several open questions remain for the Division of Buildings and Grounds / ICT Centre before development starts (§16 of SPEC.md): volume/format of historical records, whether Smart Services shares a SQL Server instance, authoritative building code list, current technician team/zone structure, existing SLA policy, LINE OA existence, server OS (Windows/IIS vs Linux/Nginx), extension availability (`pdo_sqlsrv`, `imagick`), data retention policy, and outbound HTTPS access for the Anthropic/LINE APIs.

## Constraints

- **Tech stack**: PHP 8.2+ (API), Microsoft SQL Server 2019+ (`pdo_sqlsrv`), Vanilla JS/HTML/CSS frontend (no build step) — matches university IT's existing environment and expertise
- **AI**: Anthropic Claude API via Guzzle (no official PHP SDK) — Sonnet for full analysis, Haiku for screening/duplicate adjudication, cost target ≤ THB 0.60/ticket
- **No infra add-ons**: no Redis, no Elasticsearch, no vector database — SQL Server alone (`job_queue` table replaces a message queue) to minimize operational burden on university IT
- **Messaging**: LINE Messaging API + LIFF only — zero install for reporters and technicians
- **Compliance**: PDPA — automatic PII redaction, hashed identifiers, defined retention (images 2yr, tickets 5yr), consent on first LINE link
- **Async AI**: all AI work happens in a background worker; the API must respond immediately and intake must never fail because AI is down
- **Auditability**: all rule-based decisions (SLA, routing, cost) are plain PHP, not LLM — auditable and unit-testable; `ticket_events` is append-only at the DB level

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| SQL Server + `job_queue` table instead of Redis/BullMQ | Minimize operational burden on university IT; matches existing division systems | — Pending |
| Character-trigram + Dice coefficient for duplicate detection, no vector DB | Thai has no word spacing, tokenization is unreliable; trigrams work without segmentation and avoid a vector DB dependency | — Pending |
| Routing is deterministic PHP code, never LLM | Auditable, testable, and removes a hallucination risk from a decision that affects dispatch fairness | — Pending |
| `safety_hazard=1` hard-forces `priority=P1` regardless of model confidence | Missing a hazard is an unacceptable risk (§11.2 requires ≥95% P1 recall) | — Pending |
| No JS framework / no build step for frontend | No Node on the production host; minimal long-term maintenance burden for university IT | — Pending |
| Vanilla HTML/CSS/JS + LIFF instead of native mobile app | Zero install for reporters and technicians; LIFF runs inside LINE's in-app browser | — Pending |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd:complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-08-18 after initialization*

# Roadmap: UP-Fix — Smart Maintenance Request System

## Overview

UP-Fix goes from an empty database to a fully closed-loop maintenance system in five phases. Phase 1 lays the data foundation, intake API, and async job-queue infrastructure everything else depends on. Phase 2 is a dedicated, quality-gated AI classification pipeline — hazard force-escalation, PII redaction, and duplicate detection all have to clear a ≥95% P1-recall bar on a labelled eval set before any UI is built against their output, because that's the one part of the system with a non-negotiable, externally-measured quality bar. Phase 3 wires deterministic (non-LLM) routing, confidence-tiered human triage, and the Thai-business-day SLA engine on top of real classified tickets. Phase 4 is the multi-channel launch — LINE OA intake/status tracking for reporters, a triage/assignment dashboard for staff, and a LIFF mobile view for technicians — the point at which a ticket can move end-to-end from a reporter's photo to a closed job. Phase 5 adds the legacy Smart Services import and the repeat-repair/AI-cost analytics that give leadership a evidence base for budget decisions.

## Phases

**Phase Numbering:**

- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [ ] **Phase 1: Foundations, Data Layer & Job Queue Infrastructure** - Schema, intake API, and async worker infra ready for AI processing
- [ ] **Phase 2: AI Classification Pipeline (gated)** - Classification, hazard escalation, PII redaction, and duplicate detection validated against the labelled eval set
- [ ] **Phase 3: Routing, Dispatch & SLA** - Deterministic routing, confidence-tiered triage, and Thai-business-day SLA clocks
- [ ] **Phase 4: Multi-Channel Launch — LINE, Staff Dashboard & Technician LIFF** - Reporter, staff, and technician interfaces wired to real classified/routed tickets
- [ ] **Phase 5: Analytics & Legacy Import** - Repeat-repair, AI-cost, and legacy-import analytics for leadership

## Phase Details

### Phase 1: Foundations, Data Layer & Job Queue Infrastructure

**Goal**: The system has a reliable data foundation, ticket intake API, and async job-queue infrastructure ready to run AI processing safely, with an immutable audit trail from the first event.
**Mode:** mvp
**Depends on**: Nothing (first phase)
**Requirements**: AUDIT-01
**Success Criteria** (what must be TRUE):

  1. A ticket can be created via the internal intake API (photo and/or text) and is persisted with a unique ticket number.
  2. Every ticket state change is captured as an append-only `ticket_events` record that cannot be edited or deleted after the fact.
  3. Enqueued AI jobs are claimed atomically by a worker process, with no double-processing on concurrent workers or crash-retry.
  4. The buildings/assets reference data needed for classification and routing is seeded and queryable (or explicitly flagged as placeholder pending university data).

**Plans:** 3 plans

Plans:
**Wave 1**

- [ ] 01-01-PLAN.md — Walking skeleton: scaffold, migrations, and text ticket intake end-to-end with an immutable audit trail (AUDIT-01)

**Wave 2** *(blocked on Wave 1 completion)*

- [ ] 01-02-PLAN.md — Job queue infrastructure: atomic claim, worker loop with idempotency seam, and stale-lock crash recovery

**Wave 3** *(blocked on Wave 2 completion)*

- [ ] 01-03-PLAN.md — Photo intake with MIME sniffing, remaining SPEC section 5 tables, placeholder reference data, AC-14 concurrency proof, and /healthz

### Phase 2: AI Classification Pipeline (gated, P1 recall ≥95%)

**Goal**: Every submitted ticket is automatically classified with an evidence-backed decision — safety hazards are force-escalated to P1 with zero tolerance for missed hazards, PII is redacted from stored images, and near-duplicate tickets are caught before dispatch — validated against the labelled eval set before any downstream phase depends on this output.
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: INTAKE-03, AI-01, AI-04, DUP-01, DUP-03, AUDIT-02, AUDIT-04, AUDIT-05
**Success Criteria** (what must be TRUE):

  1. A submitted ticket receives a structured AI classification (category, subcategory, priority, location, summary, evidence citation) plus a predicted materials list.
  2. A ticket flagged `safety_hazard=true` is always assigned `priority=P1` and triggers immediate supervisor notification regardless of model confidence, measured at ≥95% recall on the labelled eval set.
  3. Stored images have faces/license plates redacted, and the original unredacted image is purged within 24 hours.
  4. Near-duplicate tickets are flagged before dispatch (SQL filter → trigram/Dice → LLM adjudication for borderline cases), and a hazard-flagged ticket is never auto-merged regardless of similarity score.
  5. Identical symptom text yields identical priority classification regardless of reporter identity or building (fairness regression test, AC-10, passes).

**Plans**: TBD

### Phase 3: Routing, Dispatch & SLA

**Goal**: Classified tickets are deterministically routed to the correct technician team, confidence-tiered triage sends uncertain cases to humans instead of auto-assigning, and SLA clocks run correctly against Thai business days.
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: ROUTE-01, AI-02, SLA-01, SLA-02
**Success Criteria** (what must be TRUE):

  1. A classified ticket is auto-assigned to a technician team based on skills, zone, workload, and asset repair continuity, using deterministic PHP logic — never an LLM call.
  2. A ticket with classification confidence below 0.75 is routed to a human triage queue instead of being auto-assigned.
  3. Each ticket has an SLA due time computed from its priority (P1–P4) against Thai business days/holidays, with time spent "on hold" excluded from the clock.

**Plans**: TBD

### Phase 4: Multi-Channel Launch — LINE, Staff Dashboard & Technician LIFF

**Goal**: Reporters, staff, and technicians each have a working interface — LINE OA intake and status tracking, a staff triage/assignment dashboard, and a technician LIFF view — so a ticket can move end-to-end from a reporter's photo to a closed job.
**Mode:** mvp
**Depends on**: Phase 2, Phase 3
**Requirements**: INTAKE-01, INTAKE-02, STATUS-01, STATUS-02, AUDIT-03, AI-03, DUP-02, DASH-01, DASH-04, TECH-01, TECH-02
**Success Criteria** (what must be TRUE):

  1. A reporter can submit a ticket via LINE OA with a photo and/or text, get an immediate acknowledgement, and receive the AI classification result within ~30–60 seconds.
  2. A reporter receives LINE push notifications as their ticket moves through assigned → in_progress → completed, and can submit satisfaction feedback after completion.
  3. Staff can view a dashboard of open jobs, SLA breaches, and workload per team; act on a triage queue of low-confidence/duplicate-adjudication cases; correct a classification with a recorded reason; and merge/unmerge duplicates reversibly.
  4. A technician can open an assigned job in LIFF, see the photo/symptoms/asset history, and close the job — with an "after" photo required (blocking) for P1/P2 jobs.
  5. LINE user identifiers are stored only as hashed values, never raw.

**Plans**: TBD
**UI hint**: yes

### Phase 5: Analytics & Legacy Import

**Goal**: Management can see repeat-repair costs, AI spend, and historical trends grounded in imported legacy maintenance data.
**Mode:** mvp
**Depends on**: Phase 1, Phase 3, Phase 4
**Requirements**: DASH-02, DASH-03, LEGACY-01
**Success Criteria** (what must be TRUE):

  1. Historical Smart Services maintenance records are imported and linked to assets to seed repeat-repair baseline analytics.
  2. The dashboard shows repeat-repair analysis per asset with accumulated cost.
  3. The dashboard shows a monthly AI cost report (spend per ticket/model).

**Plans**: TBD
**UI hint**: yes

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Foundations, Data Layer & Job Queue Infrastructure | 0/3 | Planned | - |
| 2. AI Classification Pipeline (gated) | 0/TBD | Not started | - |
| 3. Routing, Dispatch & SLA | 0/TBD | Not started | - |
| 4. Multi-Channel Launch — LINE, Staff Dashboard & Technician LIFF | 0/TBD | Not started | - |
| 5. Analytics & Legacy Import | 0/TBD | Not started | - |

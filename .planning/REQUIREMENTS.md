# Requirements — UP-Fix

*Generated from PROJECT.md + research/FEATURES.md, auto mode. Ground truth: SPEC.md.*

## v1 Requirements

### Intake (INTAKE)
- [ ] **INTAKE-01**: Reporter can submit a ticket via LINE OA with photo and/or text and receive immediate acknowledgement
- [ ] **INTAKE-02**: Reporter receives an AI-analyzed classification (category, subcategory, priority, location, summary) within ~30–60s of submission
- [ ] **INTAKE-03**: Every AI classification carries an `evidence` citation explaining the basis for the decision

### AI Classification & Safety (AI)
- [ ] **AI-01**: Safety hazards are force-escalated to P1 regardless of model confidence, with immediate supervisor notification
- [ ] **AI-02**: Low-confidence classifications (<0.75) route to a human triage queue instead of auto-assigning
- [ ] **AI-03**: Officers can correct AI classifications with a recorded reason
- [ ] **AI-04**: AI predicts a required-materials list for the job

### Duplicate Detection (DUP)
- [ ] **DUP-01**: Duplicate detection (SQL filter → trigram/Dice similarity → LLM adjudication for borderline cases) prevents redundant dispatch
- [ ] **DUP-02**: Duplicate merges are reversible (merge/unmerge)
- [ ] **DUP-03**: A ticket flagged `safety_hazard=true` is never auto-merged regardless of similarity score — always routed to human adjudication

### Routing (ROUTE)
- [ ] **ROUTE-01**: Job routing to technicians is deterministic PHP logic (skills, zone, workload, asset repair continuity) — not LLM-driven

### Reporter Status Tracking (STATUS)
- [ ] **STATUS-01**: Reporter can track ticket status end-to-end via LINE push notifications (assigned → in_progress → completed)
- [ ] **STATUS-02**: Reporter can give satisfaction feedback after job completion

### Technician (TECH)
- [ ] **TECH-01**: Technician can view job details (photo, symptoms, asset history) via LIFF
- [ ] **TECH-02**: Technician can close a job with a required "after" photo, enforced for P1/P2 jobs

### Dashboard & Analytics (DASH)
- [ ] **DASH-01**: Manager/director dashboard shows open jobs, SLA breaches, and workload per team
- [ ] **DASH-02**: Dashboard shows repeat-repair analysis with accumulated cost per asset
- [ ] **DASH-03**: Dashboard shows monthly AI cost report
- [ ] **DASH-04**: Staff triage queue for low-confidence/duplicate-adjudication cases with assignment actions

### SLA (SLA)
- [ ] **SLA-01**: SLA engine (P1–P4) computed against Thai business days/holidays
- [ ] **SLA-02**: On-hold time is excluded from the SLA clock

### Audit & Compliance (AUDIT)
- [ ] **AUDIT-01**: Full audit trail (`ticket_events`, append-only) for every state transition and reclassification
- [ ] **AUDIT-02**: PII protection — automatic face/license-plate redaction on stored images
- [ ] **AUDIT-03**: LINE user IDs are hashed, never stored raw
- [ ] **AUDIT-04**: Raw (unredacted) images are purged within 24h
- [ ] **AUDIT-05**: Fairness enforcement — identical symptoms yield identical priority regardless of reporter seniority or building (tested, AC-10)

### Legacy Data (LEGACY)
- [ ] **LEGACY-01**: Legacy Smart Services history import for repeat-repair analytics baseline

## v2 Requirements (Deferred)

- Preventive maintenance (PM) scheduling module
- Inventory / spare-parts integration
- Predictive maintenance / IoT sensor integration
- Offline technician mode
- Energy-meter correlation
- Rollout / change-management phase (formal training materials, adoption tracking) — flagged by research as commonly under-scoped for CMMS launches; keep lightweight for v1

## Out of Scope

- Procurement / disbursement workflows — integration point only, not owned by this system
- Individual technician performance evaluation — all analytics aggregated at team level only, enforced in code
- Room or vehicle booking — existing systems already cover this
- Fully autonomous AI decisions — AI proposes, humans approve/override, always
- Native mobile app — LINE OA + LIFF + web dashboard only
- Any JS framework requiring a build step — plain HTML/CSS/JS only, no Node on production host
- Vector DB / embeddings for duplicate detection — trigram/Dice is the chosen approach (Thai has no word spacing)
- Real-time websocket updates — polling is sufficient at this scale (per research/ARCHITECTURE.md)

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| INTAKE-01 | Phase 4 | Pending |
| INTAKE-02 | Phase 4 | Pending |
| INTAKE-03 | Phase 2 | Pending |
| AI-01 | Phase 2 | Pending |
| AI-02 | Phase 3 | Pending |
| AI-03 | Phase 4 | Pending |
| AI-04 | Phase 2 | Pending |
| DUP-01 | Phase 2 | Pending |
| DUP-02 | Phase 4 | Pending |
| DUP-03 | Phase 2 | Pending |
| ROUTE-01 | Phase 3 | Pending |
| STATUS-01 | Phase 4 | Pending |
| STATUS-02 | Phase 4 | Pending |
| TECH-01 | Phase 4 | Pending |
| TECH-02 | Phase 4 | Pending |
| DASH-01 | Phase 4 | Pending |
| DASH-02 | Phase 5 | Pending |
| DASH-03 | Phase 5 | Pending |
| DASH-04 | Phase 4 | Pending |
| SLA-01 | Phase 3 | Pending |
| SLA-02 | Phase 3 | Pending |
| AUDIT-01 | Phase 1 | Pending |
| AUDIT-02 | Phase 2 | Pending |
| AUDIT-03 | Phase 4 | Pending |
| AUDIT-04 | Phase 2 | Pending |
| AUDIT-05 | Phase 2 | Pending |
| LEGACY-01 | Phase 5 | Pending |

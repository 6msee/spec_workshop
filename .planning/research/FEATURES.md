# Feature Research

**Domain:** CMMS / facilities maintenance request system, university context, AI-assisted intake via chatbot
**Researched:** 2026-08-18
**Confidence:** MEDIUM (web search, cross-checked across multiple independent vendor/analyst/practitioner sources; no official standards body for this domain — see Sources)

## Feature Landscape

### Table Stakes (Users Expect These)

Features any CMMS / facilities maintenance product needs, or the product feels broken. Cross-referenced against SPEC.md §4 — items marked **[SPEC ✓]** are already planned; items marked **[GAP]** are common in the category but not currently in scope.

| Feature | Why Expected | Complexity | Notes |
|---------|--------------|------------|-------|
| Work order/ticket lifecycle (create → assign → in-progress → complete → closed) | Core CMMS function across every vendor (UpKeep, MaintainX, Limble, SAP, IBM) | MEDIUM | **[SPEC ✓]** §5.8 state machine, richer than most (adds `on_hold`, `needs_info`, `duplicate`, `rejected`) |
| Priority/urgency triage | Reporters and staff both expect danger to jump the queue; without it, cosmetic and hazardous faults compete equally | MEDIUM | **[SPEC ✓]** P1–P4 with hazard force-escalation, exceeds typical CMMS which often has only 2-3 levels |
| Asset linkage + repair history | Enables repair-vs-replace decisions, is standard in every FM software surveyed | MEDIUM | **[SPEC ✓]** §5.2 `assets`, optional linkage — correctly scoped as non-blocking (works with empty asset table) |
| Staff triage/assignment dashboard | Every CMMS has a "central hub" queue view; without it staff can't operate at scale | MEDIUM | **[SPEC ✓]** §2.2, §4 dashboard requirement |
| Mobile-friendly technician view (photo before/after, close job) | Technicians work in the field; desktop-only is a known adoption killer for FM software | MEDIUM | **[SPEC ✓]** LIFF `tech.html`, camera capture, mandatory after-photo for P1/P2 |
| Automatic routing by location + category + urgency | Cited repeatedly as the difference between "digital form" and "actual CMMS" — manual dispatcher sorting is the #1 complaint replaced by CMMS adoption | HIGH | **[SPEC ✓]** §4.6 deterministic PHP routing (skills, zone, workload, continuity) |
| SLA tracking / breach escalation | Every FM software surveyed lists SLA dashboards as core reporting | MEDIUM | **[SPEC ✓]** §4.2, business-day-aware, escalates at 100%/150% |
| Status visibility for the requester | Reduces "where's my ticket" follow-up calls — cited as reducing duplicate submissions too | LOW | **[SPEC ✓]** LINE push notifications, poll endpoint |
| Photo/text attachment on request | Standard intake mechanism in every modern CMMS request portal | LOW | **[SPEC ✓]** multipart upload, ≤5 images |
| Audit trail of who changed what | Standard for accountability in facilities/asset-heavy systems, and required for university compliance/budget justification | MEDIUM | **[SPEC ✓]** `ticket_events`, append-only, DB-level `DENY UPDATE/DELETE` — exceeds typical CMMS audit logging |
| Duplicate detection / merge | Cited as preventing a 10–20% duplicate rate that otherwise inflates every backlog metric | HIGH | **[SPEC ✓]** §4.5, three-layer (SQL filter → trigram/Dice → LLM), reversible merge — more rigorous than typical vendor CMMS (most only dedupe on exact asset+location match) |
| Preventive maintenance (PM) scheduling | Universally listed as a "core" CMMS feature (time/usage/condition-based recurring tasks) alongside work orders | HIGH | **[GAP — likely intentional]** UP-Fix is a **reactive-request** system only; no PM scheduling module exists in SPEC.md. This is consistent with the project's framing ("maintenance *request* system," not full CMMS) but worth flagging explicitly as a scope boundary for the roadmap, since stakeholders familiar with commercial CMMS (Limble, MaintainX) may expect it |
| Inventory / spare-parts tracking | Standard CMMS feature everywhere surveyed | MEDIUM | **[SPEC ✓ deferred]** Explicitly out of scope (§1.2 non-goal: procurement/disbursement — integration point only) and listed under §14 "Won't have" as roadmap item. Correctly deferred, not missing by oversight |

### Differentiators (Competitive Advantage)

Features that set UP-Fix apart from a standard CMMS/ticketing product — mostly what makes this an AI-assisted-intake system rather than a manual-form CMMS. All items below are already in SPEC.md; this section validates *why* they matter against the ecosystem research rather than proposing new ones.

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| Zero-domain-knowledge intake (photo + one sentence via chat, no form/category picker) | Nearly every CMMS still requires the reporter to pick a category/trade from a dropdown — the #1 friction point this research surfaced for non-technical reporters (students/faculty who don't know "HVAC" vs "electrical"). Doing this via LINE chat instead of a web form portal removes install friction *and* domain-knowledge friction simultaneously | HIGH | **[SPEC ✓]** R-1; this is the core differentiator per PROJECT.md Core Value |
| AI classification with mandatory `evidence` citation | Directly matches the explainability best practice found in AI-incident-triage research: "every AI decision needs a visible reasoning chain… without this visibility, analysts accept or reject AI decisions blindly and errors propagate undetected." Most commercial CMMS AI add-ons (e.g., predictive-maintenance upsells) do not expose reasoning | MEDIUM | **[SPEC ✓]** §4.3, §10.3 — well-aligned with domain best practice, worth preserving as a hard requirement, not a nice-to-have |
| Confidence-threshold routing (auto-assign / human-review / human-triage tiers) | Matches the "confidence-threshold routing" pattern found across both AI-support-triage and AI-incident-triage research as the accepted best practice, avoiding both over-automation and under-automation | MEDIUM | **[SPEC ✓]** §4.4 — see Pitfalls note below on threshold calibration risk |
| Hard-forced P1 on `safety_hazard=1`, immune to model confidence | Matches "severity-calibrated escalation" best practice (highest-severity cases get mandatory human escalation) but goes further by removing AI confidence from the hazard decision entirely — a stronger guarantee than typical AI-incident-triage systems, which usually still gate escalation on some confidence floor | LOW | **[SPEC ✓]** §4.2 hard rule, §11.2 requires ≥95% P1 recall — appropriately the single highest-scrutiny requirement in the spec |
| Duplicate detection tuned for Thai text (trigram/Dice, no tokenizer, no vector DB) | Generic CMMS duplicate-detection (asset+location exact match) misses paraphrased free-text duplicates — the exact failure mode the "free-text vs picklist breaks matching" research finding describes. Thai's lack of word-spacing makes standard NLP tokenization/embeddings brittle, so this is a genuinely non-obvious, domain-correct choice | HIGH | **[SPEC ✓]** §4.5 — differentiator vs. commercial CMMS that assume English/tokenizable text or bundle a vector DB the university doesn't want to operate |
| Predicted materials list | No commercial CMMS surveyed derives a materials list from an AI read of a photo+text report — most predictive-materials features require a pre-existing asset BOM. This is intake-time inference, not asset-catalog lookup | MEDIUM | **[SPEC ✓]** §4.3 `suggested_materials` — genuinely novel per this research; flag for extra scrutiny in eval (§11) since it has no direct precedent to benchmark against |
| Repeat-repair / accumulated-cost analytics for replace-vs-repair decisions | Directly matches the "asset history enables smarter replace decisions" best practice — but most CMMS require *manual* asset tagging on every ticket for this to work; UP-Fix's asset-linkage is AI-assisted (asset_guess) at intake time, lowering the data-entry burden that normally causes this analytics feature to have gappy data | MEDIUM | **[SPEC ✓]** M-2, `/analytics/repeat-repairs` |
| Fairness enforcement (identical symptoms → identical priority regardless of reporter seniority/building) | Not found anywhere in the commercial CMMS or AI-triage literature surveyed — this appears to be a genuinely novel safeguard specific to a university power-dynamic context (a dorm resident's report must be treated the same as the Rector's office) | LOW (as a rule) / MEDIUM (as a tested guarantee) | **[SPEC ✓]** AC-10, §10.4 — recommend keeping as an automated regression test, not just a policy statement, exactly as SPEC.md already does |

### Anti-Features (Commonly Requested, Often Problematic)

Cross-checked against SPEC.md §1.2 Non-Goals — all of SPEC's stated non-goals are validated below as correct calls against the ecosystem research; two additional risks are flagged that aren't yet explicit non-goals.

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|------------------|-------------|
| Individual technician performance scoring | Managers commonly ask for per-technician KPIs once a CMMS ships (response time, close rate) | Research on AI-triage failure modes stresses that metrics used for individual evaluation create incentive to game classification/closure (mis-close to hit SLA, avoid hard jobs). University context (staff union/HR sensitivities) makes this a legal/labor risk, not just a UX one | **[SPEC ✓ already excluded]** Team-level aggregation only, enforced in code (§10.4) — correct, keep enforcing at the query layer, not just UI |
| Fully autonomous AI decision-making (no human approval step) | Tempting once accuracy looks good in a demo — "just auto-assign everything above X% confidence" | Directly the "over-automation without escalation" failure mode this research found repeatedly: accuracy decays over time (95%→60% in 90 days is a cited real-world pattern) without a human feedback loop, and silent misroutes compound because nobody is watching | **[SPEC ✓ already excluded]** AI proposes, humans approve/override always; confidence-tiered human review queue (§4.4) is the correct mitigation |
| Native mobile app | Feels more "professional"/app-store-visible than a chat bot | Every install-friction study and this project's own reporter base (students who won't install a niche university app) favors zero-install; LIFF/LINE-in-app-browser achieves the same UX without app store distribution/update overhead | **[SPEC ✓ already excluded]** LINE OA + LIFF + web dashboard |
| Vector database / embeddings for duplicate or classification search | Seems like the "modern AI" way to do semantic dedup once you have LLM classification anyway | Adds an operational dependency the university explicitly cannot support (no dedicated infra team for Elasticsearch/pgvector-class systems), and for Thai text specifically, generic multilingual embeddings underperform character-trigram similarity because Thai has no word boundaries (this is not a workaround, it's a stated domain-specific improvement) | **[SPEC ✓ already excluded]** SQL Server-only trigram + Dice coefficient, `job_queue` table instead of a message broker |
| Real-time/websocket live map or live queue updates | Feels like a modern SaaS "polish" feature once a dashboard exists | Adds infrastructure complexity (persistent connections, scaling websockets on IIS/Windows-or-Nginx uncertain hosting) for a workload of ~500 tickets/day where polling with backoff is indistinguishable in practice | Not addressed explicitly in SPEC.md — **recommend adding as an explicit non-goal** given SPEC.md §8.6 already commits to `setTimeout` polling (2s→30s backoff), which is the right call; flagging so a future contributor doesn't "upgrade" to websockets unprompted |
| AI auto-closing tickets without required after-photo, even for low-priority | Tempting labor-saver ("P4 tickets are cosmetic, why require proof") | The 20%-reassignment red flag and accuracy-decay pattern in the AI-triage research both argue for keeping a human/photographic checkpoint even on low-severity items, just a lighter one | **[SPEC ✓ already handled]** §12.3 — after-photo required (hard) for P1/P2, warned-but-allowed for P3/P4; this graduated approach matches the "severity-calibrated escalation" best practice exactly |
| Preventive maintenance (PM) module in this phase | Every commercial CMMS bundles it, so stakeholders may ask "why can't it also schedule PM?" once they see the AI intake working | Building a PM scheduling engine (recurring task generation, meter-based triggers, template inheritance across the building hierarchy) is a materially different feature domain from reactive-request triage and would roughly double scope without validating the core AI-intake hypothesis first | Treat explicitly as a **v2+ roadmap item**, not a silent gap — see Gaps note above; do not let it creep into the current milestone |

## Feature Dependencies

```
AI Classification (category/priority/location/evidence)
    └──requires──> Image quality gate + PII redaction (must run first, before any model call)
                       └──requires──> job_queue async worker (never inline on the webhook)

Duplicate Detection (trigram/Dice + LLM adjudication)
    └──requires──> AI Classification (needs category + building_id to scope the SQL candidate filter)
                       └──requires──> text_signature precomputed and stored (ai_classifications)

Job Routing (deterministic PHP)
    └──requires──> AI Classification (needs required_skills, category, building_id/zone)
    └──requires──> Technician availability/skills data (independent of AI — must exist before routing can work at all)

Confidence-tiered Human Triage Queue
    └──requires──> AI Classification (confidence score is the routing key)
    └──enhances──> Hazard force-escalation (hazard bypasses confidence entirely, but still needs classification to *detect* the hazard in the first place)

SLA Engine (P1–P4 clocks)
    └──requires──> Priority (from AI Classification, or human override)
    └──requires──> Business-day/holiday calendar (independent data, must be seeded before go-live)

Repeat-repair / Replace-vs-repair Analytics
    └──requires──> Asset linkage (asset_guess from AI, or manual link)
    └──requires──> Legacy Smart Services history import (for a non-trivial historical baseline — cold-start with zero history makes this feature useless for months)

Fairness Testing (AC-10)
    └──requires──> Job Routing being deterministic, non-LLM code (an LLM-driven router could not be given this guarantee reliably)

Preventive Maintenance module (NOT in current scope)
    └──would require──> Asset hierarchy + meter/schedule data model (largely absent from current schema — this is why it's correctly deferred, not just deprioritized)
```

### Dependency Notes

- **AI Classification requires the image pipeline to run first:** PII redaction and the quality gate must complete before the classification call, both to avoid sending unredacted faces to a third-party API and because a blurry/dark/irrelevant image should short-circuit before spending AI cost on it. This ordering is implicit in SPEC.md §3's worker sequence (redaction → quality gate → classification) — keep it explicit in the roadmap's phase ordering so classification isn't planned before the media pipeline exists.
- **Duplicate detection requires classification, not vice versa:** the SQL pre-filter narrows candidates by `building_id` + `category`, both of which come from the AI output. This means duplicate detection cannot be built/tested meaningfully in a phase that precedes classification — it has no signal to filter on.
- **Routing requires two independent inputs:** AI-derived skills/category *and* a technician roster with skills/zone/status that has nothing to do with AI. The roster data (and its CRUD) is a prerequisite that's easy to underestimate as "just seed data" — it needs its own phase/plan, not a bullet inside the routing plan.
- **Repeat-repair analytics is data-starved without the legacy import:** this is the single biggest "looks small, isn't" dependency in the roadmap — the feature is a table-stakes differentiator (M-2 director story) but is worthless without the Smart Services import (§13/§15), which itself has unresolved open questions (§16: does it share a SQL Server instance?). Sequence the import work early, or explicitly plan for an empty-history demo state.
- **Fairness testing depends on routing staying rule-based:** this is a structural argument reinforcing SPEC.md's own decision (Key Decision: "Routing is deterministic PHP code, never LLM") — an LLM-based router would make AC-10 unfalsifiable/unstable across runs, not just harder to test.

## MVP Definition

SPEC.md §14 already defines a hackathon demo scope; this section validates it against the research rather than re-deriving it.

### Launch With (v1) — matches SPEC.md §14 "Must have"

- [ ] LINE OA intake with photo + text — the core differentiator (zero-domain-knowledge reporting) validated above; without it there's no product thesis to test
- [ ] AI classification (category, priority, summary, evidence) — table stakes for any AI-triage claim, and evidence citation is the explainability best practice this research repeatedly surfaced as non-negotiable
- [ ] Hazard detection forcing P1 + notification — the single highest-risk failure mode (missed danger) identified across both the CMMS and AI-triage research; must ship in v1, not be deferred
- [ ] Duplicate detection (trigram + Dice) — prevents the 10-20% duplicate-inflation pattern found universally in CMMS research; without it, early demo data will look worse than it is
- [ ] Staff dashboard: job queue, triage queue, assignment — table stakes; a CMMS without a working queue is a form, not a system
- [ ] Technician LIFF view: accept job, close with after photo — table stakes; a system technicians can't use in the field won't get adopted
- [ ] Repeat-repair analysis over real historical data — this is the persuasion artifact for university leadership (M-2); depends on the legacy import landing early (see Dependency Notes)
- [ ] Complete `ticket_events` audit log — table stakes for university accountability/compliance, and a prerequisite for trusting any other analytics

### Add After Validation (v1.x) — matches SPEC.md §14 "Should have"

- [ ] Automatic face/plate blurring — legally required (PDPA) but can trail the demo if a manual review step covers the gap during initial pilot
- [ ] Predicted materials list — genuinely novel differentiator (no precedent found in research), but has no acceptance-criteria bar yet (§11 doesn't score it); validate it doesn't mislead technicians before promoting it from "shown" to "trusted"
- [ ] SLA report by team — valuable but requires enough ticket volume to be meaningful; adding it post-pilot avoids reporting on noise
- [ ] Monthly AI cost dashboard — operationally important once real traffic exists, not needed to prove the concept

### Future Consideration (v2+) — matches SPEC.md §14 "Won't have" plus this research's additions

- [ ] Inventory/spare-parts API integration — standard CMMS feature, but explicitly a downstream-system integration, not core to the AI-intake thesis
- [ ] Predictive maintenance / IoT sensors — different feature domain entirely (condition-based triggers, sensor ingestion); defer until reactive-intake is proven
- [ ] Energy-meter correlation — nice analytics extension, no dependency on anything else in v1
- [ ] Offline mode for technicians — real pain point in low-signal buildings, but solving it well (conflict resolution on sync) is its own project; don't let it block LIFF v1
- [ ] Full preventive-maintenance scheduling module — flagged above as a common CMMS expectation not yet in scope; worth an explicit roadmap placeholder so stakeholders know it was considered, not missed

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| LINE OA photo+text intake | HIGH | MEDIUM | P1 |
| AI classification with evidence | HIGH | HIGH | P1 |
| Hazard force-escalation to P1 | HIGH | LOW | P1 |
| Confidence-tiered human triage routing | HIGH | MEDIUM | P1 |
| Deterministic job routing | HIGH | MEDIUM | P1 |
| Duplicate detection (trigram/Dice) | HIGH | HIGH | P1 |
| SLA engine (business-day aware) | HIGH | MEDIUM | P1 |
| Staff triage/assignment dashboard | HIGH | MEDIUM | P1 |
| Technician LIFF close-job flow | HIGH | MEDIUM | P1 |
| Audit trail (`ticket_events`) | HIGH | LOW | P1 |
| PII redaction pipeline | HIGH (compliance-blocking) | MEDIUM | P1 |
| Repeat-repair analytics | HIGH (for leadership buy-in) | MEDIUM (HIGH if legacy import is messy) | P1 |
| Legacy Smart Services import | MEDIUM (invisible to reporters, unlocks P1 analytics) | UNKNOWN (blocked on §16 open questions) | P1 (de-risk early) |
| Predicted materials list | MEDIUM | MEDIUM | P2 |
| SLA report by team | MEDIUM | LOW | P2 |
| Monthly AI cost dashboard | LOW-MEDIUM (ops-facing) | LOW | P2 |
| Fairness regression test (AC-10) | MEDIUM (risk mitigation, not user-facing) | LOW | P2 (build once routing is stable) |
| Inventory/spare-parts integration | MEDIUM | HIGH (external system) | P3 |
| Preventive maintenance module | MEDIUM-HIGH (expected by CMMS-literate stakeholders) | HIGH (new data model) | P3 |
| Predictive maintenance / IoT | LOW (no sensors deployed yet) | HIGH | P3 |
| Offline technician mode | LOW-MEDIUM (only matters in dead zones) | HIGH | P3 |

**Priority key:**
- P1: Must have for launch (matches SPEC.md §14 "Must have" + the two data-dependency items this research flags as risk)
- P2: Should have, add when possible (matches SPEC.md §14 "Should have")
- P3: Nice to have, future consideration (matches SPEC.md §14 "Won't have")

## Competitor Feature Analysis

No direct competitor targets a Thai-language, LINE-based, university-facilities niche — the comparison below is against the general CMMS category (UpKeep, MaintainX, Limble, Urbest/Oxmaint's education-vertical offerings) rather than named head-to-head competitors, since none were found serving this exact niche.

| Feature | Typical Commercial CMMS | Education-vertical CMMS (Oxmaint/Urbest) | UP-Fix Approach |
|---------|--------------------------|-------------------------------------------|------------------|
| Intake channel | Web form / native app, category dropdown required | Web portal, mobile app, QR code scan-to-report | LINE chat, zero dropdown — photo + one sentence, AI infers category |
| Routing | Rule-based on category+location, manually configured | Same, plus safety-issue fast path to supervisors | AI-derived skills/category feeds deterministic PHP routing (skills, zone, workload, repair continuity) — closer to education-vertical pattern but with AI doing the categorization step humans/dropdowns normally do |
| Duplicate handling | Mostly exact match on asset+location; some vendors offer "similar ticket" suggestions | Not detailed in sources found | Three-layer SQL→trigram/Dice→LLM adjudication, Thai-text-aware — more rigorous than anything found in commercial CMMS research |
| Language | English-first; some offer i18n UI strings only | Same | Thai-first reporter-facing text, bilingual support, Thai business-day/holiday SLA clock — not found as a first-class concern in any surveyed vendor |
| AI transparency | Predictive-maintenance AI upsells rarely expose reasoning to end users | Not detailed in sources found | Mandatory `evidence` field, visible confidence score, "AI got this wrong" correction button — matches the AI-incident-triage explainability best practice, exceeds commercial CMMS AI features surveyed |
| Fairness/equity safeguards | Not found in any source surveyed | Not found in any source surveyed | Explicit AC-10 test (identical symptoms → identical priority regardless of reporter status) — appears to be a genuinely novel feature for this category, likely specific to UP-Fix's university power-dynamics context |

## Sources

- [UpKeep — What is a CMMS?](https://help.onupkeep.com/en/articles/398039-what-is-a-cmms)
- [MaintainX — CMMS Software Guide](https://www.getmaintainx.com/blog/what-is-cmms)
- [SAP — What is CMMS Software?](https://www.sap.com/resources/what-is-cmms)
- [IBM — What is a CMMS?](https://www.ibm.com/think/topics/what-is-a-cmms)
- [Limble — CMMS Software Guide for Maintenance Teams](https://limble.com/learn/cmms)
- [Oxmaint — University and College Campus Maintenance Management](https://oxmaint.com/industries/government/university-college-campus-maintenance-management)
- [Urbest — CMMS for Universities: The Complete 2026 Guide](https://urbest.io/blog/cmms-for-universities-the-complete-2026-guide-for-higher-education-facility-managers/)
- [Oxmaint — School & University Maintenance Software](https://oxmaint.com/article/school-university-maintenance-cmms-campus-facilities)
- [LLumin — How To Avoid Duplicate Data In CMMS Integrations](https://llumin.com/blog/how-to-avoid-duplicate-data-in-cmms-integrations/)
- [Oxmaint — Work Order Management Best Practices](https://oxmaint.com/blog/post/blog-post-work-order-management-best-practices)
- [Augment Code — How AI Ticket Triage Workflows Route Engineering Work](https://www.augmentcode.com/guides/ai-ticket-triage)
- [DevRev — AI support ticket triaging strategies: the enterprise playbook](https://devrev.ai/blog/ai-support-ticket-triaging)
- [Beagle — AI Support Ticket Triage Cuts Misrouting by Half](https://www.heybeagle.com/blog/ai-support-ticket-triage-cuts-misrouting-by-half)
- [Moveworks — What is IT Ticket Triage? And how does AI enhance it?](https://www.moveworks.com/us/en/resources/blog/ai-powered-it-ticket-triage)
- [arXiv — AI-based Classification of Customer Support Tickets](https://arxiv.org/pdf/2406.01789)
- [Underdefense — AI-Enabled Incident Triage: Implementation Playbook](https://underdefense.com/blog/ai-enabled-incident-triage/)
- [Panther — AI-Enabled Incident Triage: How Teams Investigate Faster](https://panther.com/blog/ai-enabled-incident-triage)
- [PagerDuty — Automated Incident Handling Best Practices](https://www.pagerduty.com/resources/automation/learn/best-practices-automated-incident-handling/)
- [ServiceChannel — Preventive Maintenance vs Reactive Maintenance](https://servicechannel.com/blog/preventive-maintenance-vs-reactive-maintenance/)
- [mpulse — SLA Maintenance Tracking for Facility Managers](https://mpulsesoftware.com/blog/cmms/sla-maintenance-tracking-for-facility-managers/)
- Internal: `SPEC.md` v2.0 (2026-08-18), `.planning/PROJECT.md` — used throughout as the baseline to validate against, not as an external research source

---
*Feature research for: CMMS / university facilities maintenance request system with AI-assisted chatbot intake*
*Researched: 2026-08-18*

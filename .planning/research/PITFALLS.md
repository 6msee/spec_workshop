# Pitfalls Research

**Domain:** AI-assisted maintenance triage / CMMS with safety-critical hazard detection, PDPA-sensitive photo handling, and a SQL-Server-only async job queue
**Researched:** 2026-08-18
**Confidence:** MEDIUM (cross-referenced multiple independent web sources per topic; no official Anthropic/Microsoft docs consulted for this pass — verify SQL Server locking specifics against MS docs during implementation)

This file validates and supplements — rather than repeats — the mitigations SPEC.md already specifies (§4.3, §4.4, §10, §11, §12). Each pitfall below is either a gap in those mitigations, a way the stated mitigation can fail silently, or a risk that only appears once the system is running in production.

## Critical Pitfalls

### Pitfall 1: Using the vision LLM's bounding boxes as the *only* PII redaction layer

**What goes wrong:**
SPEC.md §10.1 has the vision model itself return normalised face/plate bounding boxes, which `Redactor.php` then pixelates. If the model misses a face (false negative) — turned away, poorly lit, small in the background, partially occluded — that face ships to the dashboard unredacted, because there is no independent check. AC-11 only tests the *happy path* ("a clearly visible face").

**Why it happens:**
General-purpose vision LLMs are not purpose-built object detectors; their spatial/coordinate localization is measurably weaker than dedicated face/plate detection models, especially in exactly the conditions maintenance photos are shot in (dim rooms, motion, odd angles, phone cameras). Classification confidence (`confidence: 0.81` in the schema) reflects the *category/priority* judgment, not detection recall for redaction boxes — nothing in §4.3 flags redaction uncertainty separately.

**How to avoid:**
- Treat the LLM's bounding boxes as advisory, not authoritative. Bias `Redactor.php` toward over-blurring: apply a safety margin (e.g. expand each box 15–20%) rather than pixelating exactly the reported region.
- Evaluate redaction recall as its own metric in `bin/eval.php`, separate from classification accuracy — build a small labelled set of photos with known faces/plates in varied lighting/angles and measure miss rate specifically.
- Consider a cheap secondary pass (a dedicated face-detection library/heuristic, even a simple Haar-cascade or a second Haiku call primed only for "does this image contain any human face, yes/no, describe location") as a second opinion before marking `redacted = 1`.
- Never treat `redacted = 1` as a compliance guarantee without this second signal — it currently means "the model said it redacted," not "no PII remains."

**Warning signs:**
- Redaction eval only ever run once at launch instead of continuously with fresh samples
- No metric tracked for redaction false-negative rate in production monitoring (§11.3 only tracks classification correction rate)
- Complaints/incidents surface where a served image shows a recognizable face

**Phase to address:**
The PII redaction / image pipeline phase — before any image is ever served to a non-admin role.

---

### Pitfall 2: P1 recall is measured once at launch, but missed hazards produce no ongoing signal in production

**What goes wrong:**
§11.2 requires ≥95% P1 recall on the held-out test set, validated pre-launch. But once live, there is no symmetric mechanism to detect recall failures. §11.3's "AI got this wrong" button and weekly correction-rate dashboard only catch cases where a *human notices and flags* — which systematically undercounts missed hazards, because a missed hazard usually looks like a routine P3/P4 ticket that nobody double-checks. The team can watch the correction-rate dashboard stay green while real hazards quietly slip through as low-priority tickets.

**Why it happens:**
Precision failures are self-reporting (someone got annoyed and clicked a button); recall failures are invisible unless someone goes looking for them. This is the textbook "we don't know what we don't know" blind spot in any classifier's production monitoring, and it's especially dangerous here because the one metric the spec treats as non-negotiable (P1 recall ≥95%) is exactly the one type of failure production monitoring, as currently specified, cannot see.

**How to avoid:**
- Add a scheduled audit process (weekly/monthly) where a supervisor samples a random set of closed non-P1 tickets and re-reviews the original photo/text specifically for missed hazard indicators — a manual recall-oriented spot-check, not a correction-rate metric.
- Instrument a secondary trigger: if a *new* P1 ticket is created for the same `asset_id`/`building_id`+`room` shortly after a P3/P4 ticket about a similar `category` was closed, flag the closed ticket for hazard-recall review (a plausible pattern for "the AI under-triaged the first report and it got worse").
- Re-run `bin/eval.php` against the held-out set on every prompt version change (§11.3 already tracks `prompt_version`) — recall must be re-verified, not just spot-checked, whenever the prompt changes.

**Warning signs:**
- No process exists beyond the "AI got this wrong" button for surfacing recall failures
- `bin/eval.php` is run once and never again after a prompt revision
- No correlation check between closed low-priority tickets and later high-priority tickets at the same location

**Phase to address:**
The model quality / evaluation phase, and again at the monitoring/analytics phase — this is a process gap, not a one-time code fix.

---

### Pitfall 3: Trusting the model's self-reported `confidence` field as a calibrated probability

**What goes wrong:**
§4.4's routing thresholds (`≥0.75` auto-assign, `0.50–0.74` review, `<0.50` human triage) assume the model's `confidence` value behaves like a real probability. LLM-reported confidence scores are well documented to be poorly calibrated — models are frequently overconfident, and the *distribution* of self-reported scores can cluster in ways that don't match actual accuracy at that score. If the model says `0.81` on cases that are actually correct only 60% of the time, the 0.75 auto-assign threshold silently auto-assigns wrong classifications far more often than the ≥85% category-accuracy target implies.

**Why it happens:**
Confidence-threshold routing designs are usually built assuming the number "0.75" behaves the way it looks like it should. Nobody validates the calibration curve (accuracy vs. reported confidence, bucketed) until real accuracy data proves otherwise — usually after auto-assigned wrong classifications have already caused a mis-routed technician dispatch.

**How to avoid:**
- Before launch, use the labelled 300-record test set (§11.1) to plot a calibration curve: for each confidence bucket (e.g. 0.70–0.79, 0.80–0.89...), what fraction of predictions in that bucket were actually correct?
- Set `CONFIDENCE_AUTO_ASSIGN` and `CONFIDENCE_HUMAN_TRIAGE` based on where the *empirical* accuracy crosses acceptable thresholds, not the round numbers 0.75/0.50 as a starting guess.
- Re-check calibration whenever `AI_PROMPT_VERSION` changes — a new prompt can shift the confidence distribution even if it improves raw accuracy.

**Warning signs:**
- `.env.example` thresholds (0.75/0.50) were never revisited after the eval dataset was built
- No calibration plot exists anywhere in `bin/eval.php` output — only accuracy/recall/precision numbers
- Staff correction rate on auto-assigned (`≥0.75`) tickets is meaningfully higher than the corrected-classification target (≤15%, §11.2)

**Phase to address:**
The model quality / evaluation phase, before confidence routing goes live with real dispatch consequences.

---

### Pitfall 4: The anti-hallucination guardrail depends on an authoritative building/asset list that doesn't exist yet

**What goes wrong:**
§4.3's core hallucination control is: "`building_code` / `asset_code` must exist in the database; if not found, set to `null`." This only works if `buildings` and `assets` are seeded with a complete, correct, authoritative list *before* classification goes live. PROJECT.md/§16 Open Question 3 states this list's location is still unknown. If the seed data is incomplete (missing a building, using a different code format than reporters/technicians actually use), the system will systematically null out valid classifications and route them to human triage — inflating the "share of tickets requiring human triage" metric (§11.2 target ≤20%) for a reason that has nothing to do with model quality, and eroding trust in the "AI is helping" value proposition on day one.

**Why it happens:**
It's tempting to treat "validate against the DB" as a solved problem once the code is written, without confirming the reference data behind it is trustworthy. Reference-data completeness is a data-migration problem, not an AI problem, and gets deprioritized relative to prompt engineering work.

**How to avoid:**
- Resolve Open Question 3 (authoritative building code source) as a hard blocking dependency before any classification accuracy work is evaluated — a wrong or incomplete `buildings` seed will corrupt every eval number downstream of it.
- Cross-check the seed list against the legacy Smart Services data (Open Question 1/2) and any facilities/estates office master list; reconcile naming mismatches (e.g. "ICT" vs "ICT Building" vs "อาคาร ICT") before seeding.
- Track a specific metric: rate of `building_id = NULL` results *caused by missing reference data* vs. caused by genuine model uncertainty — these need different fixes and will otherwise be conflated in the ≤20% human-triage target.

**Warning signs:**
- Development/eval work proceeds using a placeholder or partial `buildings_up.sql` seed
- Human-triage rate is high specifically for buildings known to exist on campus
- No reconciliation step exists between legacy Smart Services building names and the new `buildings.code` values

**Phase to address:**
Data foundation phase (reference data / taxonomy setup) — must complete before the AI classification phase's eval numbers can be trusted.

---

### Pitfall 5: Trigram/Dice duplicate auto-linking silently suppresses a second, possibly worse, hazard report

**What goes wrong:**
§4.5's Layer 2/3 duplicate detection auto-links tickets at Dice ≥0.75 with no human confirmation. Two real, distinct problems in the same room often produce a *high* trigram similarity purely because Thai location tokens (room numbers, building codes like "ICT1301") dominate the character-trigram overlap, even when the actual fault differs (e.g., "แอร์ห้อง ICT1301 มีเสียงดัง" [AC noise] vs. "แอร์ห้อง ICT1301 มีน้ำหยด" [AC leak, potentially electrical hazard] — both share most of the location trigrams). If the second, more severe report gets auto-merged into the first as a duplicate, the real dispatch never happens, and reversal only occurs if someone notices — which is exactly the same "invisible until it's too late" failure shape as Pitfall 2, but for duplicate suppression instead of hazard recall.

**Why it happens:**
Dice similarity over the full `summary_th` string doesn't separate "same location" from "same symptom" — a text-similarity metric this coarse will always be vulnerable to shared boilerplate (location phrasing) inflating the score independent of the actual complaint.

**How to avoid:**
- Never auto-link (Dice ≥0.75, no confirmation) when either candidate ticket has `safety_hazard = true` or when the *incoming* report's classified category/subcategory differs from the candidate's — force these into the Layer 3 LLM-adjudication or reporter-confirmation path regardless of Dice score.
- Compute trigram similarity on a normalized text that strips or down-weights location tokens (building code, room number, floor) before comparing, so the score better reflects symptom similarity rather than location similarity.
- Because merges are already required to be reversible (§4.5), add a monitoring query: tickets that were auto-merged as duplicates but whose original photo/text, on later human review, describes a different fault — track this as a distinct metric from general duplicate-detection precision (§11.2 target ≥80% is an aggregate; a hazard-specific miss inside that aggregate can hide easily).

**Warning signs:**
- No exclusion rule prevents `safety_hazard=true` tickets from auto-merging via Layer 3
- `text_signature` is computed on raw `summary_th` including location phrasing, not a symptom-focused subset
- Duplicate-detection precision (§11.2) is tracked only in aggregate, with no hazard-specific breakdown

**Phase to address:**
The duplicate-detection phase — add the hazard exclusion rule and location-token normalization before Layer 3 goes live.

---

### Pitfall 6: Non-idempotent async job handlers cause double AI billing and duplicate reporter notifications on retry

**What goes wrong:**
`job_queue` (§5.7) is correctly designed for *safe claiming* (atomic `UPDATE TOP(1) ... WITH (ROWLOCK, READPAST, UPDLOCK)`), which prevents two workers from processing the *same* queued row simultaneously. But safe claiming does not make the job *handler* idempotent. If `ClassifyTicketHandler` calls the Anthropic API successfully, then the process dies (crash, OOM, host reboot) before the `ai_classifications` INSERT commits or before `job_queue.status` is set to `done`, the scheduler's stale-lock reset (§5.7, 10-minute timeout) will requeue the job — and the retry calls the paid Claude API a second time for the same ticket, and (if it gets further) `NotifyLineHandler` can push a duplicate "Analysis complete" or duplicate satisfaction-survey message to the reporter.

**Why it happens:**
"At-least-once" is the natural delivery guarantee for any DB-polling queue with crash-recovery via lock timeout (the spec's own design). Developers correctly implement the *claim* safety (no double-claim) but often stop there, assuming that's sufficient — it prevents concurrent double-processing but not sequential double-processing after a crash-and-retry.

**How to avoid:**
- Before calling the Anthropic API in `ClassifyTicketHandler`, check whether an `ai_classifications` row already exists for this `ticket_id` + `prompt_version` within the current job's attempt window; if so, skip the API call and proceed with the existing result (idempotent by ticket, not just by job row).
- For `NotifyLineHandler`, key sent-notification state off `ticket_events` (e.g., don't push "assigned" again if a `notified_assigned` event already exists for this ticket) rather than trusting the job only ran once.
- Track `attempts` in cost reporting (§9.4) — a spike in `input_tokens`/`output_tokens` per ticket relative to `attempts > 1` is a direct signal this is happening.

**Warning signs:**
- Handlers call external APIs (Anthropic, LINE) as their first action with no existence check
- Monthly AI cost report shows tickets with `attempts > 1` costing proportionally more than the ≤ THB 0.60/ticket target
- Reporters occasionally report receiving the same LINE message twice

**Phase to address:**
The async worker/job-queue infrastructure phase — idempotency belongs in the handler base class or a shared decorator, not left to each handler author to remember.

---

### Pitfall 7: Optimizing purely for P1 recall (≥95%) causes over-triggering that produces P1 alert fatigue

**What goes wrong:**
§11.2 sets a P1 recall floor of ≥95% but only a 60% precision floor, explicitly favoring over-flagging. This is the right call for missed hazards, but it has a second-order consequence the spec doesn't address: if 40% of "hazard" flags are false positives, and P1 volume is high enough, supervisors who get paged "immediately" (§4.2, §12.3) on every P1 begin to desensitize to the notification — exactly the alert-fatigue pattern seen in security operations centers, where high false-positive rates measurably correlate with *missed* real incidents because responders start dismissing alerts faster. A system explicitly designed to over-flag hazards can end up defeating its own purpose if supervisor response quality degrades under volume.

**Why it happens:**
The recall-first design is correct in isolation, but nobody sizes the resulting P1 *volume* against realistic supervisor/technician capacity, or differentiates notification channels by how confident vs. borderline the hazard flag is.

**How to avoid:**
- Track P1 volume as a first-class production metric from week one, not just accuracy/recall — if P1 tickets exceed a sustainable rate (compare against historical Smart Services P1-equivalent volume, once that data exists per Open Question 1), that's a signal precision needs tightening even though 60% is "acceptable" per spec.
- Differentiate the notification: a `safety_hazard=true` result with high model confidence and clear evidence can page immediately; a hard-forced P1 sitting in `needs_review` (§4.4's "any + safety_hazard=true → assign as P1 immediately AND request parallel human confirmation") might warrant a distinguishable notification tier so supervisors can triage their own attention.
- Periodically review false-positive P1s with supervisors to confirm the notification is still being taken seriously (a qualitative check, not just a metric).

**Warning signs:**
- P1 notification volume climbs and response time to P1 pages (not just SLA compliance) starts drifting upward
- Supervisors self-report "I stopped checking every P1 alert immediately"
- No differentiation exists between a high-confidence hazard flag and a borderline one in the notification itself

**Phase to address:**
The safety-hazard / notification phase, and revisited in production monitoring once real P1 volume is known.

---

### Pitfall 8: EXIF metadata and embedded JPEG thumbnails survive the visible-pixel redaction pipeline

**What goes wrong:**
§10.1 focuses redaction entirely on pixelating detected face/plate *regions* of the image content. It does not mention stripping EXIF metadata. Reporter phone photos routinely embed GPS coordinates, device identifiers, and precise timestamps in EXIF — this is personal data under PDPA independent of whether a face is visible, and separately, many JPEG files carry an embedded EXIF *thumbnail* (a small preview image) that most pixel-editing pipelines (including Imagick operations that only touch the main image plane) leave untouched. If `Redactor.php` blurs the visible face but re-saves without stripping metadata/thumbnail, a technically "redacted" (`redacted=1`) file can still leak an unredacted thumbnail-sized face or precise GPS location to anyone who inspects the file's metadata.

**Why it happens:**
"Redaction" naturally reads as "the picture I see is blurred," and the embedded-thumbnail behavior of JPEG is a well-known but easy-to-miss gotcha — it's not visible when viewing the image normally, only when inspecting raw file bytes or metadata.

**How to avoid:**
- After pixelating detected regions, explicitly strip all EXIF/XMP/IPTC metadata (`Imagick::stripImage()` or equivalent) before writing the redacted file, and confirm no embedded thumbnail survives (Imagick's `stripImage()` removes the EXIF thumbnail tag as part of full metadata strip — verify this empirically for the specific Imagick version/build in use).
- Do this even for images with *no detected face*, since GPS/device metadata is personal data regardless of visible content, and the reporter's `gps_lat`/`gps_lng` are already captured as structured fields — there's no need to also retain them embedded in the file.
- Add a test case that inspects the byte-level metadata of a redacted output file, not just its visual appearance, as part of AC-11's automated verification.

**Warning signs:**
- AC-11's test only checks that the visible image looks blurred, never inspects file metadata
- No `stripImage()` (or equivalent) call exists in `Redactor.php`
- Original GPS coordinates can be extracted from a served (`redacted=1`) file with a standard EXIF reader

**Phase to address:**
The PII redaction / image pipeline phase — same phase as Pitfall 1, same file (`Redactor.php`).

---

## Technical Debt Patterns

Shortcuts that seem reasonable but create long-term problems.

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|-----------------|------------------|
| Ship confidence thresholds (0.75/0.50) as round numbers without a calibration pass | Faster launch, no extra eval work | Auto-assigns wrong classifications at a rate the ≥85% accuracy number doesn't predict (Pitfall 3) | Never for launch; acceptable only as a temporary placeholder pre-eval-dataset |
| Rely solely on the vision LLM for redaction bounding boxes | No extra dependency, simpler pipeline | Silent PII leaks on low-recall cases (Pitfall 1) | Never — PDPA exposure risk is asymmetric (one miss = one real leak) |
| Skip location-token normalization in trigram duplicate matching | Simpler `text_signature` computation | False-positive duplicate merges on same-room, different-fault tickets (Pitfall 5) | Only acceptable if hazard-exclusion rule (never auto-merge safety_hazard tickets) is implemented as a hard guard regardless |
| Correction-rate dashboard as the only production quality signal | Cheap to build, uses data already captured | Recall failures (missed hazards) are invisible by construction (Pitfall 2) | Never for the P1 recall requirement specifically; acceptable for lower-stakes category corrections |
| No job-handler idempotency check, relying only on atomic claim | Less code per handler | Double AI billing / duplicate notifications after crash-retry (Pitfall 6) | Never — cheap to add, expensive to debug in production |
| Seed `buildings`/`assets` with a partial or placeholder list to unblock development | Development can start before Open Question 3 is answered | Eval numbers and human-triage rate become meaningless until real data lands (Pitfall 4) | Acceptable for local dev/demo only, never for the eval run that gates production launch |

## Integration Gotchas

Common mistakes when connecting to external services.

| Integration | Common Mistake | Correct Approach |
|-------------|-----------------|-------------------|
| Anthropic API (Guzzle, no official PHP SDK) | Retrying on a Guzzle timeout without knowing whether the prior request actually completed server-side — can double-bill and produce two `ai_classifications` rows for one ticket if the idempotency check (Pitfall 6) isn't in place | Check for an existing classification before calling; log request/response latency and treat ambiguous timeouts conservatively (query before retry) |
| LINE Messaging API | Handling webhook redelivery (already covered via `webhookEventId`, §12.3) but forgetting that *outbound* push messages from job handlers (assignment/status-change notifications) can also be sent twice on handler retry | Make `NotifyLineHandler` idempotent against `ticket_events`, not just make the webhook intake idempotent |
| SQL Server (`pdo_sqlsrv`) job claiming | Assuming `UPDATE TOP (1) ... WITH (ROWLOCK, READPAST, UPDLOCK)` alone is enough for performance at scale; without a supporting filtered index on `(status, run_after)` for `job_queue`, the claim query can degrade to a scan as the table grows | Add a filtered index (`WHERE status = 'pending'`) analogous to the one already defined for `tickets.sla_resolve_by` (§5.3) |
| Legacy Smart Services import | Assuming legacy category/building naming maps 1:1 onto the new `Taxonomy.php` / `buildings.code` values | Build and test the reconciliation mapping explicitly (§13.5 `SmartServicesImporter`) before trusting repeat-repair analytics baselines |

## Performance Traps

Patterns that work at small scale but fail as usage grows.

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|-----------------|
| `job_queue` rows never archived/purged after `status = 'done'` | Claim query (`UPDATE TOP(1) ... WHERE status='pending'`) slows as the table accumulates years of completed job history | Scheduled purge/archive of `done`/`failed` rows older than N days, mirroring the `_raw` media purge pattern already in the spec | Noticeable once the table passes roughly hundreds of thousands of rows — plausible within 1–2 years at 500 tickets/day × 5 job types/ticket |
| `ai_classifications` append-only growth with no archival strategy | Dashboard/analytics queries joining against it slow down at the 100,000-ticket scale the spec itself targets (§9.1) | Index on `(ticket_id, created_at)`; consider a summary/materialized table for analytics rather than joining the full append-only log every time | At or before the 100,000-ticket p95 target the spec already commits to |
| Duplicate-detection Layer 1 SQL filter depends on `building_id` being known | When AI location confidence is low and `building_id` stays `NULL` (common per Pitfall 4 until reference data is solid), Layer 1 can't narrow candidates by building, forcing wider trigram comparison | Fall back to campus-wide same-`category` filtering with a shorter lookback window when `building_id IS NULL`, rather than skipping the SQL pre-filter entirely | Visible as soon as the human-triage rate for missing-location tickets is non-trivial |

## Security Mistakes

Domain-specific security issues beyond general web security.

| Mistake | Risk | Prevention |
|---------|------|------------|
| Treating AI-reported redaction bounding boxes as ground truth for PDPA compliance | Unredacted PII served to staff/technicians who lack a need to see the reporter's or a bystander's face (Pitfall 1) | Independent redaction-recall eval; over-blur bias; never mark `redacted=1` purely on the LLM's say-so |
| Not stripping file metadata during redaction | GPS/device metadata leak even when visible pixels are blurred (Pitfall 8) | `stripImage()` (or equivalent) on every redacted output, verified at the byte level, not just visually |
| Passing abusive/accusatory reporter text into `ticket_events.reason` or manager-facing exports unfiltered (§12.1 already excludes it from the LLM prompt, but doesn't say it's excluded from staff-facing exports) | Accusatory content about a named individual persists in an audit log that managers/directors can later export, creating a secondary PDPA/HR risk beyond the AI pipeline itself | Apply the same exclusion/redaction rule to any staff-facing export or report generation, not just the LLM prompt path |
| Serving uploaded files via `readfile()` after only a `finfo_file()` MIME check | A crafted polyglot file (valid image header + embedded script) could pass MIME sniffing and be served with a permissive `Content-Type`, risking stored-content issues in browsers that still sniff content | Force `Content-Type` from the *validated* MIME type (never trust the uploaded byte stream's internal claims beyond what `finfo` confirms), set `Content-Disposition` appropriately, and re-encode images through Imagick (which naturally strips non-image payloads) rather than serving the uploaded bytes verbatim |

## UX Pitfalls

Common user experience mistakes in this domain.

| Pitfall | User Impact | Better Approach |
|---------|-------------|-------------------|
| False-positive duplicate match ("this was already reported") discourages a reporter with a genuinely different problem at the same location from re-reporting | A real second fault (potentially worse than the first) never gets logged because the reporter accepts the "already reported" framing and drops it | Make the "different issue" quick-reply button (already in §7's UX copy) prominent and low-friction, and specifically avoid auto-linking without confirmation for anything touching a hazard candidate (ties to Pitfall 5) |
| Tickets sitting in the 0.50–0.74 `needs_review` band (§4.4) give the reporter no visible status update while awaiting the 1-hour officer confirmation window | Reporter perceives silence as "nothing is happening," prompting duplicate follow-up reports or complaint calls — the exact problem (§1.1) this system exists to eliminate | Send an interim LINE message during `needs_review` distinct from the final analysis push, e.g. "Your report is being reviewed by staff, expect an update within 1 hour" |
| Northern Thai dialect / tradesperson slang silently lowers confidence and reduces priority without the reporter knowing why | A reporter using unfamiliar-to-the-model phrasing gets a worse experience (slower routing) than a reporter using standard Thai, invisibly | Track category/priority accuracy stratified by text style if possible in the eval set (§11.1 already requires dialect/slang examples — extend to stratified accuracy reporting, not just aggregate) |

## "Looks Done But Isn't" Checklist

Things that appear complete but are missing critical pieces.

- [ ] **PII redaction (`redacted=1`):** Often missing EXIF/metadata stripping and embedded-thumbnail removal — verify at the byte level with an EXIF reader, not just by looking at the image (Pitfall 8)
- [ ] **P1 recall ≥95% (§11.2):** Often verified once at launch and never again — verify a recurring production audit-sampling process exists, not just the one-time `bin/eval.php` pass (Pitfall 2)
- [ ] **Confidence routing thresholds (§4.4):** Often set to the spec's example numbers (0.75/0.50) without empirical calibration — verify a calibration curve was actually plotted against the labelled test set (Pitfall 3)
- [ ] **Duplicate merge reversibility (§4.5):** Often verified only for `status` reverting to its prior value — verify `sla_respond_by`/`sla_resolve_by` and any notifications sent during the merged period are also correctly restored/re-sent on unmerge
- [ ] **Job handler idempotency:** Often verified only via the atomic-claim SQL (no double *claim*) — verify handlers are also safe against sequential retries after a crash (no double *side effects*) (Pitfall 6)
- [ ] **Fairness AC-10:** Often verified only with paired *text* test cases — verify image-only submissions (no text) are also tested for building/seniority-blind priority, since the vision channel is untested by a text-pairing approach
- [ ] **Building/asset code validation (§4.3):** Often verified against a placeholder seed list during development — verify against the actual authoritative source once Open Question 3 is resolved, and re-run the full eval after re-seeding (Pitfall 4)

## Recovery Strategies

When pitfalls occur despite prevention, how to recover.

| Pitfall | Recovery Cost | Recovery Steps |
|---------|-----------------|------------------|
| A missed hazard is discovered in production (Pitfall 2) | HIGH | Immediate incident review with the affected ticket; add the case to the labelled eval set; re-run `bin/eval.php`; if a systemic pattern emerges, adjust the safety_hazard prompt/threshold and re-validate recall before resuming normal routing |
| A bad duplicate merge suppressed a real second fault (Pitfall 5) | MEDIUM | Unmerge via the existing reversible mechanism; manually restore correct SLA clock timestamps; notify both reporters; add the case as a negative example to duplicate-detection tuning |
| Unredacted PII discovered in a served image (Pitfall 1, 8) | HIGH | Immediately pull the affected media from serving; regenerate with a corrected redaction pass; audit all images processed by the same pipeline version for the same failure class; this is a PDPA incident and may require formal notification depending on university policy |
| Runaway AI cost from non-idempotent retries (Pitfall 6) | LOW–MEDIUM | Add the missing idempotency check; audit `ai_classifications` for duplicate rows per `ticket_id`+`prompt_version` and reconcile the cost report; consider a temporary circuit breaker that halts retries above `max_attempts` more conservatively while the fix ships |
| P1 alert fatigue observed (supervisors dismissing pages) (Pitfall 7) | MEDIUM | Tighten precision (raise the safety_hazard confidence bar for immediate paging while keeping the hard P1-force rule intact), add a notification-tier distinction, and directly survey supervisors on trust in the alert channel |
| `job_queue`/`ai_classifications` performance degradation from unbounded growth (Performance Traps) | LOW | Add the missing index and a purge/archive job; this is a straightforward migration + scheduled task, no data-correctness risk |

## Pitfall-to-Phase Mapping

How roadmap phases should address these pitfalls.

| Pitfall | Prevention Phase | Verification |
|---------|-------------------|----------------|
| Vision-LLM-only redaction bounding boxes (Pitfall 1) | PII redaction / image pipeline phase | Redaction-recall eval on a labelled varied-condition photo set, tracked as its own metric separate from classification accuracy |
| Recall blindness in production (Pitfall 2) | Model quality / evaluation phase + ongoing monitoring/analytics phase | Recurring manual audit-sampling process exists and is documented as a runbook, not just a one-time eval script |
| Unvalidated confidence calibration (Pitfall 3) | Model quality / evaluation phase | A calibration curve (accuracy per confidence bucket) is produced from the 300-record test set before thresholds are finalized in `.env` |
| Missing authoritative building/asset reference data (Pitfall 4) | Data foundation / reference-data phase (blocks AI classification phase) | `buildings`/`assets` seeded from a confirmed authoritative source before the eval run that gates production launch |
| Duplicate auto-merge suppressing a hazard (Pitfall 5) | Duplicate-detection phase | Hard guard: no auto-merge (any Dice score) for tickets where either side has `safety_hazard=true`; location-token-normalized trigram comparison implemented |
| Non-idempotent job handlers (Pitfall 6) | Async worker / job-queue infrastructure phase | Idempotency check present in the shared handler base/decorator, exercised by a test that kills and restarts a handler mid-job |
| P1 alert fatigue from over-triggering (Pitfall 7) | Safety-hazard / notification phase, revisited post-launch | P1 volume tracked from week one against a sustainable-capacity baseline; notification tiering implemented |
| EXIF/thumbnail metadata surviving redaction (Pitfall 8) | PII redaction / image pipeline phase | AC-11 extended to inspect served-file metadata at the byte level, not just visual appearance |
| CMMS/system adoption failure (people, not tech) | Rollout/training phase (not yet in roadmap — should be added) | Triage officers and technicians included in workflow validation before go-live; training material and change-management plan exist, not just working software |
| Legacy Smart Services data quality for repeat-repair baseline | Data foundation / legacy import phase | Reconciliation mapping between legacy and new taxonomy tested against a data sample before trusting analytics baselines |

## Sources

- [Best Practices for Controlling LLM Hallucinations at the Application Level - Parasoft](https://www.parasoft.com/blog/controlling-llm-hallucinations-application-level-best-practices/)
- [LLM Structured Output: JSON Schema, Pydantic, and Schema Enforcement - OpenLegion](https://www.openlegion.ai/en/learn/llm-structured-output)
- [LLM evaluation techniques for JSON outputs - Promptfoo](https://www.promptfoo.dev/docs/guides/evaluate-json/)
- [Alert Triage: A Complete Guide for Security Operations Teams - Prophet Security](https://www.prophetsecurity.ai/blog/alert-triage)
- [AI Alert Triage: Fix Analyst Fatigue & False Positives - Swimlane](https://swimlane.com/blog/ai-alert-triage/)
- [CORTEX: Collaborative LLM Agents for High-Stakes Alert Triage (arXiv)](https://arxiv.org/pdf/2510.00311)
- [Enhancing Privacy: Automated Detection and Blurring of Sensitive Information (Stanford CS231n)](https://cs231n.stanford.edu/2024/papers/enhancing-privacy-automated-detection-and-blurring-of-sensitive-.pdf)
- [Ego4D: Around the World in 3,000 Hours of Egocentric Video (arXiv, de-identification pipeline discussion)](https://arxiv.org/pdf/2110.07058)
- [Automatically redact PII in images with Amazon Nova - AWS Machine Learning Blog](https://aws.amazon.com/blogs/machine-learning/automatically-redact-pii-in-images-with-amazon-nova/)
- [anonymization-pipeline (faces, license plates) - GitHub](https://github.com/sotirismos/anonymization-pipeline)
- [Postgres is the only Queue you need (until 50k jobs/sec) - Medium](https://medium.com/@harsh.vaghela.work/postgres-is-the-only-queue-you-need-until-50k-jobs-sec-5931611b551c)
- [The Job Queue Problem: How to Stop Workers from Stepping on Each Other - Medium](https://medium.com/@puneet_kumar_agarwal/the-job-queue-problem-how-to-stop-workers-from-stepping-on-each-other-4b832c77d02d)
- [Keeping a Postgres queue healthy - PlanetScale](https://planetscale.com/blog/keeping-a-postgres-queue-healthy)
- [Potential Consequences of Using Postgres as a Job Queue - Microsoft Community Hub](https://techcommunity.microsoft.com/blog/adforpostgresql/potential-consequences-of-using-postgres-as-a-job-queue/4514332)
- [PostgreSQL FOR UPDATE SKIP LOCKED: The One-Liner Job Queue - DB Pro Blog](https://www.dbpro.app/blog/postgresql-skip-locked)
- [Hybrid Machine Learning Architectures for Emergency Triage: A Systematic Review (MDPI)](https://www.mdpi.com/2673-7426/6/2/21)
- [Algorithmic fairness and bias mitigation for clinical machine learning with deep reinforcement learning (PMC)](https://pmc.ncbi.nlm.nih.gov/articles/PMC10442224/)
- [Software Fairness Testing in Practice (arXiv)](https://arxiv.org/pdf/2506.17095)
- [Evaluating Algorithmic Bias in 30-Day Hospital Readmission Models (PMC)](https://www.ncbi.nlm.nih.gov/pmc/articles/PMC11066744/)
- [Avoid CMMS Implementation Failures - Maintenance Care](https://blog.maintenancecare.com/why-maintenance-care-cmms-implementation)
- [What are the most common failures in CMMS implementation? - Upkeep](https://upkeep.com/learning/most-common-failures-in-cmms-implementation/)
- [The Single Most Common Reason CMMS Projects Fail - Accruent](https://www.accruent.com/resources/blog-posts/single-most-common-reason-cmms-projects-fail)
- [Why CMMS Implementations Fail: 5 Excruciating Reasons - Panorama Consulting](https://www.panorama-consulting.com/why-cmms-implementations-fail/)
- [5 things you should know about GDPR for images - Fotoware](https://www.fotoware.com/blog/5-things-you-should-know-about-gdpr-for-images)
- [~100 Hidden Data Points in Every Photo You Share - MetaClean](https://metaclean.app/blog/photo-metadata-privacy-complete-guide)
- [Your Photos Hide Dozens of Data Points — EXIF Explained - MetaClean](https://metaclean.app/blog/what-is-exif-data-complete-guide)
- [Could Your Use of Employee Photos Be in Breach of GDPR? - Lexology](https://www.lexology.com/library/detail.aspx?g=f7d52458-ad7f-43dc-8001-8b4b81b5643f)
- SPEC.md (project-internal, v2.0, 2026-08-18) — §4.3, §4.4, §4.5, §5.7, §10, §11, §12, §16, used throughout to identify gaps rather than repeat existing mitigations

---
*Pitfalls research for: AI-assisted maintenance triage / CMMS (UP-Fix)*
*Researched: 2026-08-18*

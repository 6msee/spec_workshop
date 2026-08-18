# UP-Fix — Smart Maintenance Request System

**Division of Buildings and Grounds, University of Phayao**

| | |
|---|---|
| **Spec version** | 2.0 |
| **Status** | Draft for implementation |
| **Owner** | Division of Buildings and Grounds, University of Phayao |
| **Stack** | PHP (API) · Vanilla JS + HTML/CSS (Frontend) · Microsoft SQL Server |
| **Last updated** | 2026-08-18 |

---

## 1. Goal

Build a maintenance request system where a reporter simply **takes a photo and types a short sentence**, and AI does the rest: classifies the work type, assesses urgency, identifies the location, detects duplicates, predicts required materials, and routes the job to the correct technician team.

### 1.1 Problems being solved

Maintenance requests are currently scattered across phone calls, personal LINE chats, paper forms, and walk-ins.

| Problem | Consequence |
|---|---|
| Reporters don't know which trade a fault belongs to | Jobs routed to the wrong team, re-routing wastes days |
| Reporters describe symptoms, not causes | Technicians arrive without the right parts and must return |
| No urgency triage | Dangerous faults (exposed wiring, collapsing ceilings) sit in the same queue as cosmetic ones |
| Multiple people report the same fault | Duplicate work orders |
| No structured historical data | Budget requests have no evidence behind them |
| Reporters can't see progress | Repeated follow-up calls burden staff |

### 1.2 Non-Goals

Explicitly out of scope, to prevent scope creep:

- ❌ Procurement / disbursement workflows (integrate only)
- ❌ Individual technician performance evaluation (see §10.4)
- ❌ IoT sensors or automated fault detection in this phase
- ❌ Room or vehicle booking (existing systems already cover this)
- ❌ Replacing human decision-making — AI proposes, humans approve
- ❌ A native mobile app (LINE OA + LIFF + web only)
- ❌ Any JS framework requiring a build step (React/Vue/Next) — the frontend is plain HTML/CSS/JS

---

## 2. Personas & User Stories

### 2.1 Reporter (student / faculty / staff)

| # | Story |
|---|---|
| R-1 | As a student, I want to photograph a fault and send it via LINE, without knowing what trade it belongs to. |
| R-2 | As a reporter, I want to know what stage my request is at and when it will be resolved. |
| R-3 | As a reporter, I want to be told immediately if someone already reported this, so I don't duplicate it. |

### 2.2 Triage Officer

| # | Story |
|---|---|
| T-1 | As a triage officer, I want low-confidence AI results in a separate queue so I can decide myself. |
| T-2 | As a triage officer, I want to correct the AI's classification, and have that correction recorded for future improvement. |
| T-3 | As a triage officer, I want hazardous jobs surfaced at the top, always. |

### 2.3 Field Technician

| # | Story |
|---|---|
| F-1 | As a technician, I want to see the photo and symptoms before I go, so I bring the right parts. |
| F-2 | As a technician, I want to close a job by taking an "after" photo on my phone. |
| F-3 | As a technician, I want to see this asset's repair history. |

### 2.4 Manager / Division Director

| # | Story |
|---|---|
| M-1 | As a supervisor, I want to see open jobs, SLA breaches, and workload per team. |
| M-2 | As a director, I want to know which buildings and assets are repaired so often they should be replaced. |

---

## 3. System Architecture

```
┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
│    LINE OA       │   │  LIFF (tech UI)  │   │  Web Dashboard   │
│  (reporters)     │   │  HTML/CSS/JS     │   │  HTML/CSS/JS     │
└────────┬─────────┘   └────────┬─────────┘   └────────┬─────────┘
         │ webhook              │ fetch()              │ fetch()
         └──────────────┬───────┴──────────────────────┘
                        ▼
        ┌───────────────────────────────────┐
        │   PHP API  (public/index.php)     │
        │   Router → Middleware → Controller│
        │   Auth(JWT) · RBAC · Rate limit   │
        │   Idempotency · JSON Schema valid │
        └───────────────┬───────────────────┘
                        │ PDO (sqlsrv)
        ┌───────────────┼────────────────────┐
        ▼               ▼                    ▼
┌────────────────┐ ┌──────────────┐  ┌──────────────────┐
│  SQL Server    │ │  job_queue   │  │  storage/media/  │
│  (tables §5)   │ │  (DB table)  │  │  (outside root)  │
└────────────────┘ └──────┬───────┘  └──────────────────┘
                          │ polling
                          ▼
        ┌───────────────────────────────────┐
        │  PHP CLI Worker (bin/worker.php)  │
        │  1. PII redaction (Imagick)       │
        │  2. Image quality gate            │
        │  3. Vision classification (Claude)│
        │  4. Duplicate detection           │
        │  5. Routing rules (pure code)     │
        └───────────────────────────────────┘
```

**Architectural principles**

1. The LLM only *understands language and images*. All rule-based decisions (SLA, routing, cost calculations) are plain PHP code that can be audited and unit-tested.
2. All AI work is **asynchronous**. The API responds immediately; the worker processes in the background.
3. No Redis, no Elasticsearch, no vector database — SQL Server alone, to minimise the operational burden on university IT.

---

## 4. Requirements

### 4.1 Work Type Taxonomy

| `category` | Example `subcategory` | Responsible team |
|---|---|---|
| `electrical` | `light_out`, `power_outage`, `breaker_trip`, `exposed_wire`, `socket_damaged` | Utilities — Electrical |
| `plumbing` | `leak`, `clog`, `no_water`, `toilet_broken`, `drain_blocked` | Utilities — Plumbing |
| `hvac` | `ac_not_cooling`, `ac_water_drip`, `ac_noise`, `ac_remote` | Utilities — HVAC |
| `structural` | `ceiling_collapse`, `wall_crack`, `door_window`, `tile_broken`, `roof_leak` | Buildings |
| `elevator` | `stuck`, `door_fault`, `noise` | External contractor |
| `landscape` | `tree_fallen`, `branch_risk`, `overgrown`, `irrigation` | Grounds & Landscape |
| `safety` | `street_light_out`, `cctv_fault`, `extinguisher_expired`, `fire_alarm` | Security |
| `civil` | `road_pothole`, `manhole_missing`, `walkway_damaged` | Buildings |
| `furniture` | `desk_chair_broken`, `whiteboard`, `cabinet` | Buildings |
| `other` | — | Human triage queue |

Declared once in `src/Domain/Taxonomy.php`, from which the SQL `CHECK` constraints and the frontend JSON are generated.

### 4.2 Priority Levels and SLA

| Priority | Definition | Examples | Respond within | Resolve within |
|---|---|---|---|---|
| **P1** | Danger to life or serious property damage | Exposed live wiring, collapsing ceiling, water leaking onto an electrical panel, person trapped in a lift, fallen tree blocking a road, gas leak | 15 min | 4 hours |
| **P2** | Space or equipment unusable; teaching affected | Classroom AC failure, whole-floor power outage, no water supply | 4 hours | 2 business days |
| **P3** | Usable but degraded | A single blown lamp, dripping tap | 1 business day | 5 business days |
| **P4** | Cosmetic / non-urgent | Peeling paint, crooked sign | 3 business days | 15 business days |

"Business days" are computed in `src/Domain/Sla.php` against a `holidays` table (Thai public holidays) and office hours 08:30–16:30. **P1 uses continuous 24-hour clock time and ignores holidays.**

> **Hard rule:** if `safety_hazard = 1`, `priority` is forced to `P1` regardless of the model's confidence, and the supervisor is notified immediately.

### 4.3 AI Output Schema

The model must return exactly this shape. Validated with `opis/json-schema` on every call.

```jsonc
{
  "category": "hvac",
  "subcategory": "ac_water_drip",
  "priority": "P2",
  "safety_hazard": false,
  "hazard_reason": null,

  "location": {
    "building_code": "ICT",      // null if it cannot be determined
    "floor": 3,
    "room": "ICT1301",
    "confidence": 0.72
  },

  "asset_guess": {
    "asset_code": "AC-ICT-1301-02",
    "confidence": 0.55
  },

  "summary_th": "แอร์ห้อง ICT1301 มีน้ำหยดจากคอยล์เย็นลงบนฝ้า เกิดคราบน้ำเป็นวงกว้าง",
  "suspected_causes": ["Blocked condensate drain", "Cracked drain pan"],
  "suggested_materials": [
    { "name": "PVC pipe 3/4 in", "qty": 1, "unit": "length" }
  ],
  "required_skills": ["hvac"],
  "estimated_duration_min": 90,

  "confidence": 0.81,
  "needs_human_triage": false,
  "evidence": [
    "Image shows brown water staining on the ceiling tile directly below the evaporator coil",
    "Reporter text states 'water drips when the AC is on'"
  ],
  "clarifying_question_th": null
}
```

**Validation rules** (implemented in `src/Ai/SchemaValidator.php`):

- Use Anthropic tool-use / structured output to constrain the shape — **but still validate server-side regardless**.
- Schema mismatch → retry up to 2 times, feeding the validation error back to the model → still failing → human triage queue.
- `evidence` must contain at least one item. The model is never allowed to conclude without citing what it saw.
- `building_code` and `asset_code` must **exist in the database**. If not found, set to `null` and flag `needs_human_triage`.
- User-facing text (`summary_th`, `clarifying_question_th`) is generated in Thai; internal reasoning fields may be English.

### 4.4 Confidence Routing

| Confidence | Action |
|---|---|
| `≥ 0.75` | Auto-assign, notify reporter immediately |
| `0.50 – 0.74` | Classify but flag `needs_review`; triage officer must confirm within 1 hour |
| `< 0.50` | Do not assign. Human triage queue + ask the reporter `clarifying_question_th` |
| any + `safety_hazard = true` | Assign as P1 immediately **and** request parallel human confirmation |

All thresholds are read from `.env` — never hard-coded.

### 4.5 Duplicate Detection (no vector database)

Three layers on SQL Server alone, cheapest first.

**Layer 1 — SQL filter**

```sql
SELECT TOP (20) t.id, t.ticket_no, t.status, c.text_signature, c.output
FROM tickets t
JOIN ai_classifications c ON c.ticket_id = t.id
WHERE t.building_id = @building_id
  AND t.category    = @category
  AND t.status NOT IN ('closed','cancelled','duplicate','rejected')
  AND t.created_at >= DATEADD(day, -14, SYSUTCDATETIME())
  AND (@floor IS NULL OR t.floor = @floor)
ORDER BY t.created_at DESC;
```

**Layer 2 — Character-trigram similarity, computed in PHP**

Thai has no spaces between words, so word tokenisation is unreliable. Character trigrams with the Dice coefficient work well without any segmentation:

```
similarity = 2 × |A ∩ B| / (|A| + |B|)   where A, B are trigram sets of summary_th
```

Trigram sets are precomputed and stored in `ai_classifications.text_signature` so they are never recomputed.

**Layer 3 — LLM adjudication for borderline cases only**

| Dice score | Action |
|---|---|
| `≥ 0.75` | Auto-link as duplicate; tell the reporter: "This was already reported as UPF-…, current status is …" |
| `0.45 – 0.74` | Send up to 5 candidates to Haiku, which returns `{ "duplicate_of": "UPF-…" \| null, "reason": "…" }`, then ask the reporter to confirm |
| `< 0.45` | Create a new ticket |

> **Critical:** duplicate linking must always be **reversible** (`POST /tickets/{id}/unmerge`). An incorrect merge makes a real fault disappear from the system.

### 4.6 Job Routing (pure code, not LLM)

Selection order in `src/Domain/Routing.php`:

1. Filter technicians whose skills match `required_skills` and whose status is `available`
2. Filter to technicians covering that building's `zone`
3. Sort by open job count, ascending
4. Sort by number of past repairs on the same `asset_id`, descending (continuity matters)
5. If nobody is available, queue and alert the supervisor once a P1/P2 has waited 30 minutes

---

## 5. Data Model (Microsoft SQL Server)

### 5.0 Conventions

| Topic | Rule |
|---|---|
| Text | Always `NVARCHAR` for anything that may contain Thai. **Never `VARCHAR`.** |
| Collation | `Thai_CI_AI` recommended for searchable/sortable Thai columns |
| Primary keys | `UNIQUEIDENTIFIER DEFAULT NEWSEQUENTIALID()` (less index fragmentation than `NEWID()`) |
| Timestamps | `DATETIME2(0)` stored in **UTC** via `SYSUTCDATETIME()`. Convert to UTC+7 in the presentation layer only. |
| Booleans | `BIT` |
| Enums | SQL Server has none — use `NVARCHAR(n)` + `CHECK`, with the same values declared as PHP constants |
| JSON | `NVARCHAR(MAX)` + `CHECK (ISJSON(col) = 1)` |
| Money | `DECIMAL(12,2)`. Never `FLOAT`. |
| Coordinates | `DECIMAL(10,7)` |

### 5.1 `buildings`

| Column | Type | Notes |
|---|---|---|
| `id` | `UNIQUEIDENTIFIER` PK | `DEFAULT NEWSEQUENTIALID()` |
| `code` | `NVARCHAR(20)` UNIQUE NOT NULL | e.g. `ICT`, `PKY`, `CE` |
| `name_th` | `NVARCHAR(200)` NOT NULL | |
| `zone` | `NVARCHAR(50)` | Used for distance-based routing |
| `lat`, `lng` | `DECIMAL(10,7)` NULL | Matched against reporter GPS |
| `floors` | `INT` NULL | |
| `gross_area_sqm` | `DECIMAL(12,2)` NULL | For faults-per-area statistics |
| `is_active` | `BIT` NOT NULL DEFAULT 1 | |

### 5.2 `assets`

| Column | Type | Notes |
|---|---|---|
| `id` | `UNIQUEIDENTIFIER` PK | |
| `asset_code` | `NVARCHAR(50)` UNIQUE NOT NULL | e.g. `AC-ICT-1301-02` |
| `building_id` | `UNIQUEIDENTIFIER` FK → `buildings` | |
| `floor` | `INT` NULL | |
| `room` | `NVARCHAR(50)` NULL | |
| `category` | `NVARCHAR(30)` + CHECK | Per §4.1 |
| `brand`, `model` | `NVARCHAR(100)` NULL | |
| `installed_at` | `DATE` NULL | For age calculation |
| `replacement_cost` | `DECIMAL(12,2)` NULL | For repair-vs-replace analysis |
| `status` | `NVARCHAR(20)` CHECK IN (`active`,`retired`) | DEFAULT `active` |

> The system must work correctly when `assets` is empty. Linking a ticket to an asset is always optional.

### 5.3 `tickets`

| Column | Type | Notes |
|---|---|---|
| `id` | `UNIQUEIDENTIFIER` PK | |
| `ticket_no` | `NVARCHAR(20)` UNIQUE NOT NULL | Format `UPF-YYYYMM-NNNNN`, see §5.9 |
| `reporter_channel` | `NVARCHAR(20)` CHECK IN (`line`,`web`,`phone`,`walkin`) | |
| `reporter_ref` | `NVARCHAR(100)` NOT NULL | Hashed LINE userId or staff ID |
| `reporter_display_name` | `NVARCHAR(200)` NULL | |
| `raw_text` | `NVARCHAR(MAX)` NULL | Verbatim reporter text |
| `building_id` | `UNIQUEIDENTIFIER` FK NULL | |
| `floor` | `INT` NULL | |
| `room` | `NVARCHAR(50)` NULL | |
| `location_note` | `NVARCHAR(500)` NULL | e.g. "outside the men's toilet, 2nd floor" |
| `gps_lat`, `gps_lng` | `DECIMAL(10,7)` NULL | |
| `asset_id` | `UNIQUEIDENTIFIER` FK NULL | |
| `category` | `NVARCHAR(30)` CHECK | Per §4.1 |
| `subcategory` | `NVARCHAR(50)` NULL | |
| `priority` | `CHAR(2)` CHECK IN (`P1`,`P2`,`P3`,`P4`) | |
| `safety_hazard` | `BIT` NOT NULL DEFAULT 0 | |
| `status` | `NVARCHAR(20)` CHECK | Per §5.8 |
| `assigned_team` | `NVARCHAR(50)` NULL | |
| `assigned_to` | `UNIQUEIDENTIFIER` FK → `technicians` NULL | |
| `duplicate_of` | `UNIQUEIDENTIFIER` FK → `tickets` NULL | |
| `sla_respond_by` | `DATETIME2(0)` NULL | |
| `sla_resolve_by` | `DATETIME2(0)` NULL | |
| `on_hold_reason` | `NVARCHAR(500)` NULL | |
| `on_hold_total_minutes` | `INT` NOT NULL DEFAULT 0 | Deducted from SLA elapsed time |
| `created_at` | `DATETIME2(0)` NOT NULL DEFAULT `SYSUTCDATETIME()` | |
| `updated_at` | `DATETIME2(0)` NOT NULL | Maintained by the application layer |
| `closed_at` | `DATETIME2(0)` NULL | |

**Indexes**

```sql
CREATE INDEX IX_tickets_queue    ON tickets(status, priority, created_at);
CREATE INDEX IX_tickets_building ON tickets(building_id, category, status);
CREATE INDEX IX_tickets_assignee ON tickets(assigned_to, status);
CREATE INDEX IX_tickets_sla_open ON tickets(sla_resolve_by)
    WHERE status NOT IN ('closed', 'cancelled');   -- filtered index
```

### 5.4 `ticket_media`

| Column | Type | Notes |
|---|---|---|
| `id` | `UNIQUEIDENTIFIER` PK | |
| `ticket_id` | `UNIQUEIDENTIFIER` FK NOT NULL | |
| `kind` | `NVARCHAR(20)` CHECK IN (`before`,`after`,`reference`) | |
| `storage_path` | `NVARCHAR(400)` NOT NULL | Relative to `storage/media/` |
| `mime_type` | `NVARCHAR(50)` NOT NULL | |
| `bytes` | `INT` NOT NULL | |
| `redacted` | `BIT` NOT NULL DEFAULT 0 | 1 = PII already blurred |
| `quality_score` | `DECIMAL(4,3)` NULL | 0–1 from the image quality gate |
| `uploaded_at` | `DATETIME2(0)` NOT NULL | |

> **Rule:** only files with `redacted = 1` are ever served. Originals live in `storage/media/_raw/` and are deleted by the scheduler within 24 hours.

### 5.5 `ai_classifications` (append-only — never overwrite)

| Column | Type | Notes |
|---|---|---|
| `id` | `UNIQUEIDENTIFIER` PK | |
| `ticket_id` | `UNIQUEIDENTIFIER` FK NOT NULL | |
| `model` | `NVARCHAR(60)` NOT NULL | Value of `AI_MODEL_PRIMARY` |
| `prompt_version` | `NVARCHAR(20)` NOT NULL | For A/B testing and rollback |
| `output` | `NVARCHAR(MAX)` CHECK (`ISJSON(output)=1`) | Per §4.3 |
| `confidence` | `DECIMAL(4,3)` NOT NULL | |
| `text_signature` | `NVARCHAR(MAX)` NULL | Trigram set of `summary_th` (JSON array) for §4.5 |
| `latency_ms` | `INT` NOT NULL | |
| `input_tokens`, `output_tokens` | `INT` NOT NULL | For cost reporting, §9.4 |
| `created_at` | `DATETIME2(0)` NOT NULL DEFAULT `SYSUTCDATETIME()` | |

### 5.6 `ticket_events` (append-only audit log)

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT IDENTITY(1,1)` PK | |
| `ticket_id` | `UNIQUEIDENTIFIER` FK NOT NULL | |
| `actor_type` | `NVARCHAR(10)` CHECK IN (`ai`,`user`,`system`) | |
| `actor_id` | `NVARCHAR(100)` NULL | |
| `event_type` | `NVARCHAR(20)` CHECK | `created`, `classified`, `reclassified`, `assigned`, `on_site`, `on_hold`, `resumed`, `completed`, `closed`, `reopened`, `cancelled`, `commented`, `merged`, `unmerged` |
| `payload` | `NVARCHAR(MAX)` CHECK (`ISJSON`) | Before/after values |
| `reason` | `NVARCHAR(500)` NULL | **Required** when `event_type = 'reclassified'` |
| `created_at` | `DATETIME2(0)` NOT NULL DEFAULT `SYSUTCDATETIME()` | |

> Grant `DENY UPDATE, DELETE ON ticket_events` to the application database user.

### 5.7 `job_queue` (replaces Redis/BullMQ)

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT IDENTITY(1,1)` PK | |
| `job_type` | `NVARCHAR(50)` NOT NULL | `redact_media`, `classify_ticket`, `find_duplicate`, `notify_line`, `escalate_sla` |
| `payload` | `NVARCHAR(MAX)` CHECK (`ISJSON`) | |
| `status` | `NVARCHAR(20)` CHECK IN (`pending`,`running`,`done`,`failed`) | DEFAULT `pending` |
| `attempts` | `INT` NOT NULL DEFAULT 0 | |
| `max_attempts` | `INT` NOT NULL DEFAULT 3 | |
| `run_after` | `DATETIME2(0)` NOT NULL DEFAULT `SYSUTCDATETIME()` | Enables exponential backoff |
| `locked_by` | `NVARCHAR(100)` NULL | Worker process name |
| `locked_at` | `DATETIME2(0)` NULL | |
| `last_error` | `NVARCHAR(MAX)` NULL | |
| `created_at`, `updated_at` | `DATETIME2(0)` NOT NULL | |

**Safe multi-worker claim** — never `SELECT` then `UPDATE`:

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

Jobs stuck in `running` with `locked_at` older than 10 minutes are reset to `pending` by `bin/scheduler.php` (worker crash recovery).

### 5.8 Ticket State Machine

```
  new ──► triaging ──┬──► assigned ──► in_progress ──┬──► completed ──► closed
                     │         ▲                     │                    │
                     │         │                     ├──► on_hold ────────┘
                     │         └─────────────────────┘   (awaiting parts)
                     │
                     ├──► needs_info  (AI or officer requests more detail)
                     ├──► duplicate   (linked to an existing ticket)
                     ├──► rejected    (not this division's responsibility)
                     └──► cancelled   (withdrawn by reporter)
```

Enforced in `src/Domain/TicketState.php` — controllers must never set `status` directly.

- `completed → closed` happens automatically after 72 hours if the reporter raises no objection
- `closed → reopened` allowed within 14 days only
- Time spent in `on_hold` and `needs_info` does **not** count toward SLA
- Any transition not on the diagram returns `409 INVALID_STATE_TRANSITION`

### 5.9 Ticket Number Generation

Format `UPF-YYYYMM-NNNNN`, where `NNNNN` restarts monthly. Uses a counter table with `UPDLOCK` to prevent collisions:

```sql
BEGIN TRAN;
  UPDATE ticket_counters WITH (UPDLOCK, HOLDLOCK)
  SET last_no = last_no + 1
  OUTPUT inserted.last_no
  WHERE period = @period;   -- '202608'
  -- if no row exists, INSERT starting at 1
COMMIT;
```

> Never use `MAX(ticket_no) + 1` — it races under concurrent submissions.

---

## 6. API Specification

**Base URL:** `/api/v1` (all requests routed through the `public/index.php` front controller)
**Auth:** `Authorization: Bearer <JWT>`, except `/webhooks/line` which uses LINE signature validation
**Content-Type:** `application/json`, except file-upload endpoints

### 6.1 Create a ticket

```
POST /api/v1/tickets
Content-Type: multipart/form-data
Idempotency-Key: <uuid>   (required)
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `text` | string | ⚠️ | At least one of `text` or `images` must be present |
| `images[]` | file | ⚠️ | Max 5 files, ≤ 10 MB each (jpeg/png/heic/webp) |
| `building_code` | string | ✗ | If known |
| `floor` | int | ✗ | |
| `room` | string | ✗ | |
| `gps_lat`, `gps_lng` | number | ✗ | |
| `channel` | enum | ✓ | `line` \| `web` |

> Set `upload_max_filesize = 12M`, `post_max_size = 64M`, `max_file_uploads = 10` in `php.ini`.
> Detect file type with `finfo_file()`. **Never trust** the client-supplied `$_FILES['type']`.

**Response `201 Created`** — returned immediately; AI work is enqueued to `job_queue`:

```json
{
  "ticket_no": "UPF-202608-00147",
  "id": "3F9A2C1E-…",
  "status": "triaging",
  "message_th": "รับเรื่องแล้ว กำลังวิเคราะห์ ระบบจะแจ้งผลภายใน 1 นาที",
  "poll_url": "/api/v1/tickets/3F9A2C1E-…"
}
```

### 6.2 Endpoints

| Method | Path | Role | Description |
|---|---|---|---|
| `POST` | `/tickets` | any | Create a maintenance request |
| `GET` | `/tickets/{id}` | reporter/staff | Detail + event timeline |
| `GET` | `/tickets` | staff | Search: `status`, `priority`, `building`, `category`, `assigned_to`, `overdue=true`, `page`, `limit` |
| `PATCH` | `/tickets/{id}` | triage/manager | Amend classification — **`reason` is mandatory** |
| `POST` | `/tickets/{id}/assign` | triage/manager | Assign to a technician |
| `POST` | `/tickets/{id}/events` | staff | Record an event (`on_site`, `on_hold`, `completed`, `commented`) |
| `POST` | `/tickets/{id}/media` | staff | Upload an `after` photo |
| `POST` | `/tickets/{id}/merge` | triage | Link as duplicate |
| `POST` | `/tickets/{id}/unmerge` | triage | Undo a duplicate link |
| `POST` | `/tickets/{id}/reopen` | reporter/staff | Reopen (within 14 days) |
| `GET` | `/media/{mediaId}` | per ticket ACL | Stream an image through PHP (§10.2) |
| `GET` | `/assets/{id}/history` | staff | Repair history for an asset |
| `GET` | `/analytics/repeat-repairs` | manager | Repeat-repair loops + accumulated cost |
| `GET` | `/analytics/sla` | manager | SLA statistics by team and building |
| `POST` | `/webhooks/line` | — | LINE Messaging API webhook |
| `GET` | `/healthz` | — | Health check (DB reachability + queue depth) |

### 6.3 Standard Error Envelope

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

| HTTP | `code` | When |
|---|---|---|
| 400 | `INVALID_PAYLOAD` | Malformed payload |
| 401 | `UNAUTHORIZED` | Missing or expired token |
| 403 | `FORBIDDEN` | Role lacks permission |
| 404 | `TICKET_NOT_FOUND` | |
| 409 | `DUPLICATE_REQUEST` | `Idempotency-Key` replay |
| 409 | `INVALID_STATE_TRANSITION` | Illegal state change |
| 413 | `FILE_TOO_LARGE` | File > 10 MB |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | Unsupported image format |
| 422 | `TICKET_EMPTY_INPUT` | Neither text nor images |
| 429 | `RATE_LIMITED` | Quota exceeded (§9.3) |
| 503 | `AI_UNAVAILABLE` | AI down — **the ticket is still created** with status `triaging` |

> Set `display_errors = Off` in production. Never leak PHP warnings or stack traces. Use `set_exception_handler()` to convert everything into the envelope above.

---

## 7. LINE OA Flow

```
Reporter sends photo + text
        │
        ▼
[immediate reply] "Received — UPF-202608-00147. Analysing…"
        │
        ▼ (< 60 s — worker finishes, then pushes)
[analysis reply]
  "🔧 HVAC — AC water leak
   📍 ICT Building, floor 3, room ICT1301
   ⚡ Priority: Medium (P2)
   👷 Assigned: HVAC team
   ⏱️ Expected completion: 20 Aug 2026
   [Button: View status] [Button: This is wrong]"
        │
        ▼ automatic push on each significant status change
   assigned → in_progress → completed
        │
        ▼
[feedback] "The job is complete. Are you satisfied? 👍 / 👎"
```

**Technical requirements**

- The webhook must **return `200` within 1 second** — the controller only writes to `job_queue` and returns. Never call the AI inline here.
- Verify the signature: `base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true))` compared against the `x-line-signature` header using `hash_equals()`.
- Fetch images via `GET /v2/bot/message/{messageId}/content`.
- **The technician UI is a LIFF page** — ordinary HTML/CSS/JS opened inside the LINE in-app browser. No separate app, and `userId` is supplied by the LIFF SDK.

**When information is missing** — quick reply:
`"Which building is this? [ICT] [PKY] [CE] [Other] [Send GPS]"`

**When a duplicate is detected:**
`"This matches UPF-202608-00131, reported 2 days ago. Status: technician on site. — [Same issue] [Different issue]"`

---

## 8. Tech Stack

### 8.1 Overview

| Layer | Technology | Rationale |
|---|---|---|
| **Backend / API** | PHP 8.2+ (hand-rolled MVC, or Slim 4 if a ready-made router is preferred) | Team expertise; matches the university's existing environment |
| **Database** | Microsoft SQL Server 2019+ | Matches existing division systems; cross-system joins are straightforward |
| **DB driver** | `pdo_sqlsrv` + Microsoft ODBC Driver 18 | Full `NVARCHAR` and parameterised-query support |
| **Frontend** | HTML5 + CSS3 + Vanilla JavaScript (ES2020 modules) | No build step, no Node on the production host, minimal maintenance |
| **Charts** | Chart.js (vendored locally, not from a CDN) | Lightweight, works directly with vanilla JS |
| **Messaging** | LINE Messaging API + LIFF | Zero install for users; LIFF pages are plain HTML/JS |
| **AI** | Anthropic Claude API via Guzzle (Sonnet for full analysis, Haiku for screening) | Multimodal, with enforceable JSON output |
| **Queue** | `job_queue` table in SQL Server + PHP CLI worker | No Redis to install or operate |
| **File storage** | Server filesystem (`storage/media/`) outside the webroot | Sufficient at university scale |
| **Image processing** | Imagick (fallback: GD) | Resize, fix EXIF orientation, convert HEIC, pixelate for PII redaction |
| **Web server** | IIS + FastCGI (Windows) or Nginx + PHP-FPM (Linux) | Either works with SQL Server; choose what university IT supports |
| **Testing** | PHPUnit 10 | Covers §12 |
| **Logging** | Monolog (JSON lines) → `storage/logs/` | |

### 8.2 Composer Dependencies

```json
{
  "require": {
    "php": ">=8.2",
    "ext-pdo_sqlsrv": "*",
    "ext-imagick": "*",
    "ext-mbstring": "*",
    "ext-fileinfo": "*",
    "ext-curl": "*",
    "guzzlehttp/guzzle": "^7.8",
    "vlucas/phpdotenv": "^5.6",
    "firebase/php-jwt": "^6.10",
    "ramsey/uuid": "^4.7",
    "opis/json-schema": "^2.3",
    "monolog/monolog": "^3.5"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  }
}
```

> There is no official Anthropic PHP SDK. Call the REST API directly with Guzzle in `src/Integration/AnthropicClient.php` (headers: `x-api-key`, `anthropic-version`).

### 8.3 Environment Variables (`.env.example`)

```bash
APP_ENV=production
APP_URL=https://upfix.up.ac.th
APP_TIMEZONE=Asia/Bangkok

# --- SQL Server ---
DB_HOST=localhost
DB_PORT=1433
DB_NAME=upfix
DB_USER=upfix_app
DB_PASS=
DB_ENCRYPT=yes
DB_TRUST_SERVER_CERT=yes

# --- Storage ---
STORAGE_PATH=/var/www/up-fix/storage      # must be outside public/
MEDIA_MAX_BYTES=10485760                  # 10 MB
MEDIA_MAX_FILES=5
MEDIA_MAX_EDGE_PX=1568                    # downscale before sending to the model

# --- Anthropic ---
ANTHROPIC_API_KEY=
ANTHROPIC_VERSION=2023-06-01
AI_MODEL_PRIMARY=claude-sonnet-4
AI_MODEL_FAST=claude-haiku-4
AI_PROMPT_VERSION=v1
AI_TIMEOUT_SECONDS=45

# --- LINE ---
LINE_CHANNEL_SECRET=
LINE_CHANNEL_ACCESS_TOKEN=
LIFF_ID=

# --- Security ---
JWT_SECRET=
JWT_TTL_MINUTES=15
REPORTER_ID_SALT=

# --- Business rules ---
CONFIDENCE_AUTO_ASSIGN=0.75
CONFIDENCE_HUMAN_TRIAGE=0.50
DUPLICATE_DICE_AUTO=0.75
DUPLICATE_DICE_ASK=0.45
DUPLICATE_LOOKBACK_DAYS=14

# --- Worker ---
WORKER_POLL_SECONDS=2
WORKER_STALE_LOCK_MINUTES=10
```

### 8.4 Project Structure

```
up-fix/
├── SPEC.md
├── composer.json
├── .env.example                  # the real .env is never committed
├── public/                       # ← document root points here only
│   ├── index.php                 # front controller (API + page serving)
│   ├── .htaccess / web.config    # rewrite everything to index.php
│   ├── dashboard.html            # manager view
│   ├── triage.html               # triage officer view
│   ├── tech.html                 # technician view (opened via LIFF)
│   ├── login.html
│   └── assets/
│       ├── css/
│       │   ├── base.css          # CSS variables, reset, Thai typography
│       │   └── components.css
│       ├── js/
│       │   ├── api.js            # fetch() wrapper: JWT, retry, error mapping
│       │   ├── auth.js
│       │   ├── ui.js             # DOM helpers (textContent only — XSS safe)
│       │   ├── dashboard.js
│       │   ├── triage.js
│       │   └── technician.js
│       └── vendor/chart.min.js
├── src/
│   ├── Http/
│   │   ├── Router.php
│   │   ├── Request.php
│   │   ├── Response.php          # JSON responses + error catalog
│   │   ├── Controllers/
│   │   │   ├── TicketController.php
│   │   │   ├── MediaController.php
│   │   │   ├── AnalyticsController.php
│   │   │   └── LineWebhookController.php
│   │   └── Middleware/
│   │       ├── AuthMiddleware.php
│   │       ├── RbacMiddleware.php
│   │       ├── IdempotencyMiddleware.php
│   │       └── RateLimitMiddleware.php
│   ├── Domain/
│   │   ├── Taxonomy.php          # §4.1 single source of truth
│   │   ├── TicketState.php       # §5.8 state machine
│   │   ├── Sla.php               # §4.2 business days + holidays
│   │   └── Routing.php           # §4.6 assignment
│   ├── Ai/
│   │   ├── Prompts/v1/
│   │   │   ├── classify.txt
│   │   │   ├── duplicate_judge.txt
│   │   │   └── redact_boxes.txt
│   │   ├── Classifier.php
│   │   ├── SchemaValidator.php   # opis/json-schema
│   │   ├── Redactor.php          # face/plate blurring via Imagick
│   │   ├── ImageQualityGate.php
│   │   ├── DuplicateFinder.php   # trigram + Dice, §4.5
│   │   └── Schemas/classification.schema.json
│   ├── Integration/
│   │   ├── AnthropicClient.php
│   │   ├── LineClient.php
│   │   └── SmartServicesImporter.php   # legacy history import, §13
│   ├── Queue/
│   │   ├── JobQueue.php          # enqueue / claim, §5.7
│   │   └── Handlers/
│   │       ├── RedactMediaHandler.php
│   │       ├── ClassifyTicketHandler.php
│   │       ├── FindDuplicateHandler.php
│   │       └── NotifyLineHandler.php
│   ├── Support/
│   │   ├── Env.php
│   │   ├── Logger.php
│   │   └── ThaiText.php          # trigrams, mb_* helpers, Buddhist-era dates
│   └── Db/
│       └── Connection.php        # PDO sqlsrv + deadlock retry (error 1205)
├── bin/
│   ├── worker.php                # job_queue consumer loop
│   ├── scheduler.php             # SLA escalation, auto-close, purge _raw, release stale locks
│   ├── migrate.php               # run .sql migrations in order
│   └── eval.php                  # §11.2 model quality measurement
├── database/
│   ├── migrations/
│   │   ├── 001_create_buildings.sql
│   │   ├── 002_create_assets.sql
│   │   ├── 003_create_tickets.sql
│   │   ├── 004_create_ticket_media.sql
│   │   ├── 005_create_ai_classifications.sql
│   │   ├── 006_create_ticket_events.sql
│   │   ├── 007_create_job_queue.sql
│   │   └── 008_create_support_tables.sql   # counters, holidays, rate_limits, idempotency_keys
│   └── seed/
│       ├── buildings_up.sql
│       └── holidays_2026.sql
├── storage/                      # ← outside the webroot
│   ├── media/
│   │   ├── _raw/                 # originals, deleted within 24 h
│   │   └── {yyyy}/{mm}/
│   └── logs/
└── tests/
    ├── Unit/
    ├── Feature/                  # exercise the API through the Router
    ├── Fixtures/
    └── eval/
        ├── dataset.json
        └── images/
```

### 8.5 Running the Worker and Scheduler

**Linux (systemd)** — two always-restarting services:

```ini
# /etc/systemd/system/upfix-worker@.service
[Service]
ExecStart=/usr/bin/php /var/www/up-fix/bin/worker.php --id=%i
Restart=always
RestartSec=5
```

Run two instances: `systemctl enable --now upfix-worker@1 upfix-worker@2`
`scheduler.php` runs from cron every minute.

**Windows Server** — Task Scheduler:
- `worker.php` — trigger "At startup", with "Restart on failure every 1 minute"
- `scheduler.php` — trigger "Every 1 minute"

> The worker must be **idempotent** and must exit voluntarily after 1,000 jobs or 30 minutes so the process is restarted and memory reclaimed — long-running PHP CLI processes leak.

### 8.6 Frontend Conventions

- **ES modules** (`<script type="module">`). No bundler, no build step.
- Every API call goes through `assets/js/api.js` so JWT attachment, retries, and Thai error messages live in one place.
- Build DOM with `document.createElement` + `textContent`. **Never `innerHTML` with user data** (§10.2).
- Poll ticket status with `setTimeout` backoff (2s → 4s → 8s, capped at 30s). Never `setInterval`.
- `tech.html` is mobile-first: large touch targets, camera capture via `<input type="file" accept="image/*" capture="environment">`.
- Render dates in the Buddhist era with `Intl.DateTimeFormat('th-TH-u-ca-buddhist')`.

---

## 9. Non-Functional Requirements

### 9.1 Performance (replacing "must be fast")

| Metric | Target |
|---|---|
| `POST /tickets` p95 (excluding AI) | < 400 ms |
| AI triage completion p95 | < 30 s from ticket creation |
| AI triage completion p99 | < 60 s |
| Dashboard query p95 | < 800 ms at 100,000 tickets |
| Worker poll interval | 2 s |
| Throughput | 500 tickets/day, burst 50/minute |
| Minimum workers | 2 processes (failover) |

### 9.2 Availability

- Uptime ≥ 99% during 07:00–19:00 on business days
- **Intake must never fail because AI is down** — the system degrades to a human triage queue
- RPO ≤ 24 h (daily SQL Server full backup + hourly transaction log backup), RTO ≤ 4 h
- `storage/media/` must be included in the backup plan — a database backup alone is insufficient

### 9.3 Rate Limits

| Scope | Quota |
|---|---|
| Per `reporter_ref` | 10 tickets/hour, 30/day |
| Per IP (web) | 60 requests/minute |
| File uploads | 20 files/hour/user |

Implemented with a `rate_limits` table (fixed window). No Redis required.

### 9.4 Cost

- AI cost per ticket ≤ THB 0.60 (based on one image plus short text)
- **Haiku** for the image quality gate, initial screening, and duplicate adjudication; **Sonnet** for full analysis
- Prompt caching for static content (taxonomy, building list, system instructions)
- Downscale images so the longest edge is ≤ 1,568 px before sending — larger costs tokens without improving accuracy
- Record token usage on every call in `ai_classifications`, surfaced as a monthly cost report

### 9.5 Localisation

- UI and reporter-facing messages are Thai by default
- English reporters are supported; reply in whichever language the reporter used
- Dates are displayed in the Buddhist era but stored in UTC
- Use `mb_*` functions everywhere Thai strings are manipulated in PHP

---

## 10. Security, Privacy and AI Governance

### 10.1 PDPA and Personal Data

- **Automatic redaction** of faces and vehicle number plates before any image is served.
  Implementation on this stack: the vision model returns normalised bounding boxes (`{x, y, w, h}` in 0–1), then `src/Ai/Redactor.php` pixelates those regions with **Imagick** (or GD) and writes a new file marked `redacted = 1`.
- Originals live in `storage/media/_raw/` and are deleted by the scheduler within 24 hours.
- LINE `userId` is stored hashed: `hash('sha256', $userId . REPORTER_ID_SALT)`. The raw value is never persisted.
- Names, phone numbers, and email addresses are never sent to the LLM — only the fault description and redacted images.
- Retention: images 2 years, ticket records 5 years (per government records regulations), then anonymised.
- Reporters may request deletion of their personal data; the ticket remains in anonymised form.
- A privacy notice and consent step is required when a user first links the LINE OA.

### 10.2 System Security

| Topic | Requirement |
|---|---|
| RBAC | Five roles: `reporter`, `technician`, `triage`, `manager`, `admin`. Reporters see only their own tickets; technicians only assigned jobs; triage/manager see everything, and exports are audited. |
| SQL injection | **PDO prepared statements only.** String-concatenated SQL is forbidden without exception. |
| Image files | Stored **outside the webroot** (`storage/media/`) and served through `GET /api/v1/media/{id}` after an authorisation check, via `readfile()`. Never place uploads under `public/`. |
| Filenames | Always regenerated as UUIDs. User-supplied filenames are discarded. |
| XSS | Frontend uses `textContent`, never `innerHTML`, for user data. Backend uses `htmlspecialchars($s, ENT_QUOTES, 'UTF-8')`. |
| CSRF | The API is token-based (no cookie sessions), so CSRF does not apply. If cookies are ever introduced, add `SameSite=Strict` plus CSRF tokens. |
| JWT | 15-minute lifetime plus refresh token. Always verify `alg`; reject `none`. |
| Transport | HTTPS enforced (TLS 1.2+), HSTS enabled. |
| Secrets | All keys in `.env`, excluded from git and located outside the webroot. |
| Audit | `ticket_events` is append-only, enforced with `DENY UPDATE, DELETE` at the database level. |

### 10.3 Transparency and Hallucination Control

| Control | Detail |
|---|---|
| **Evidence required** | Every classification must carry at least one `evidence` item referencing something actually visible in the image or present in the text |
| **No arithmetic by the LLM** | SLA, routing, accumulated cost, and all statistics are computed in PHP/SQL |
| **No invented identifiers** | `building_code` / `asset_code` are validated against the database; unmatched values become `NULL` |
| **Confidence is shown** | The staff UI always displays the confidence level and an "AI got this wrong" button |
| **Permission to be uncertain** | Below 0.50 confidence the system must say it is unsure and ask, never guess |
| **Humans decide** | AI proposes and prioritises; the supervisor approves or overrides, with the reason recorded |

### 10.4 Ethical Constraints (enforced in code, not just policy)

- 🚫 **This data must never be used to evaluate individual technicians.** No endpoint may return per-person statistics; all `/analytics/*` responses are aggregated at team level.
- 🚫 Priority must never depend on the reporter's seniority — the Rector and a first-year student reporting the same fault must receive the same priority. A unit test enforces this.
- 🚫 Images collected here must not be used for any purpose other than maintenance work.
- ✅ Bias testing is mandatory: identical symptoms reported from a student dormitory and from the administration building must yield identical priority (AC-10).

---

## 11. Model Quality and Evaluation

### 11.1 Test Dataset

- Build a labelled test set of **≥ 300 records** from real historical maintenance requests, labelled by a senior technician
- Split 70/15/15 (prompt tuning / dev / held-out test)
- Every `category` must have at least 15 examples; P1 must have at least 20
- Stored as `tests/eval/dataset.json` with images in `tests/eval/images/`

### 11.2 Acceptance Thresholds

| Metric | Minimum |
|---|---|
| `category` accuracy | ≥ 85% |
| **P1 recall (hazardous faults)** | **≥ 95%** — missing a hazard is an unacceptable risk |
| P1 precision | ≥ 60% (over-flagging is preferable to missing) |
| `building_code` accuracy (when the reporter omits it) | ≥ 70% |
| Duplicate detection precision | ≥ 80% |
| Share of tickets requiring human triage | ≤ 20% |
| Share of classifications corrected by staff | ≤ 15% |

Run with `php bin/eval.php --dataset=tests/eval/dataset.json`, which prints a confusion matrix.

### 11.3 Production Monitoring

- Model quality dashboard: weekly human-correction rate, broken down by category
- Every "AI got this wrong" click is captured as a training example for the next prompt revision
- Prompt versions are compared through `ai_classifications.prompt_version`

---

## 12. Edge Cases

### 12.1 Reporter Input

| Case | Required behaviour |
|---|---|
| Image only, no text | ✅ Proceed normally; analyse from the image alone |
| Text only, no image | ✅ Proceed, but reduce confidence by 0.15 and request a photo |
| Neither | ❌ `422 TICKET_EMPTY_INPUT` |
| Text longer than 5,000 characters | Truncate with `mb_substr()` and set the `text_truncated` flag |
| Blurry or dark image (`quality_score < 0.3`) | Ask for a retake: "Please photograph the fault clearly with the lights on" |
| Irrelevant image (selfie, screenshot, meme) | Politely state no fault was found and request a new photo. **Do not create a ticket.** |
| Image contains faces or number plates | Blur automatically before storage (§10.1) |
| HEIC image from an iPhone | Convert to JPEG with Imagick before sending to the model |
| Wrong rotation (EXIF orientation) | Correct orientation first, otherwise the model misreads the scene |
| Misspellings, Northern Thai dialect, tradesperson slang | The LLM must cope; these must appear in the §11.1 test set |
| English-language report | ✅ Supported; reply in the reporter's language |
| Not this division's responsibility (network down, broken PC) | Status `rejected` + redirect to the correct channel (ICT Centre) |
| Abusive text or a complaint about a person | Create the ticket normally, but exclude the accusatory portion from the LLM prompt and notify the supervisor |
| Rapid repeat submissions (spam) | Rate limit per §9.3 → `429` |

### 12.2 Location and Assets

| Case | Behaviour |
|---|---|
| Building unknown but GPS present | Find the nearest building within 80 m (Haversine in SQL) and ask for confirmation |
| GPS outside campus | Ignore the GPS and ask the reporter for the building |
| Model returns a building code not in the database | `building_id = NULL` + human triage. **Never auto-create buildings.** |
| Room does not exist in that building | Store the value in `location_note` instead of `room` |
| `assets` table is empty | System works normally with `asset_id = NULL` |

### 12.3 Infrastructure

| Case | Behaviour |
|---|---|
| AI API timeout or outage | The ticket is always created. Job retries 3× (backoff 2s/8s/30s via `run_after`), then falls back to human triage with the reporter told "under review by staff" |
| AI returns malformed JSON | Retry twice with the validation error fed back, then human triage |
| Worker crashes mid-job | Jobs stuck in `running` for 10+ minutes are reset to `pending` by `bin/scheduler.php` |
| LINE redelivers a webhook | `webhookEventId` used as the idempotency key in `idempotency_keys` |
| Some uploads succeed, some fail | Ticket is still created, `partial_upload` recorded, reporter told which image failed |
| Image storage full | Ticket created from text; upload job retries; admin alerted through `/healthz` |
| SQL Server deadlock | Catch error 1205 (deadlock victim) and retry the transaction up to 3×. Other errors → `503` with a Thai message |
| Technician closes without an `after` photo | ❌ Rejected for P1/P2 (`INVALID_STATE_TRANSITION`); ⚠️ warned but allowed for P3/P4 |
| Job awaiting parts | `on_hold` with `on_hold_reason`; the SLA clock stops |
| P1 raised outside office hours | Notify the duty guard and supervisor immediately on all channels |
| SLA breach | Auto-escalate to the supervisor at 100% of SLA and to the director at 150% |
| Reporter deletes their LINE message | Ticket data is retained — it is an official record |
| Server timezone is already UTC+7 | Still store UTC in the database; convert only for display, so a server migration cannot corrupt timestamps |

---

## 13. Acceptance Criteria

> Every criterion must be covered by an automated PHPUnit test.

**AC-1 — Ticket from an image alone**
**Given** a reporter sends a photo of a flickering lamp via LINE with no text
**When** the system receives it
**Then** it returns `201` with a `ticket_no` within 400 ms, and within 60 s the ticket has `category = 'electrical'` and at least one `evidence` entry.

**AC-2 — Hazards are always P1**
**Given** an image showing exposed live wiring in a corridor
**When** the AI returns `safety_hazard = true` with `priority = 'P3'`
**Then** the system overrides to `P1` and notifies the supervisor within 60 s.

**AC-3 — Empty submission**
**Given** a request with neither `text` nor `images`
**When** `POST /tickets` is called
**Then** it returns `422 TICKET_EMPTY_INPUT` and **no** database row is created.

**AC-4 — AI outage must not block intake**
**Given** the Anthropic API returns an error on every call (mocked)
**When** a reporter submits a request
**Then** the ticket is created with status `triaging`, enters the human triage queue, and the reporter receives an acknowledgement.

**AC-5 — Duplicate detection**
**Given** an open ticket "แอร์ห้อง ICT1301 ไม่เย็น"
**When** another reporter submits "ห้อง ICT1301 ร้อนมาก แอร์เสีย"
**Then** the system proposes they are the same issue and does not dispatch a second technician until confirmed.

**AC-6 — No invented building codes**
**Given** the AI returns `building_code = 'XYZ'`, absent from `buildings`
**When** the result is persisted
**Then** `building_id` is `NULL` and the ticket enters human triage.

**AC-7 — P1 closure requires an after photo**
**Given** a P1 ticket in `in_progress`
**When** the technician attempts to close it without an `after` image
**Then** it returns `409 INVALID_STATE_TRANSITION` and the status is unchanged.

**AC-8 — SLA pauses while awaiting parts**
**Given** a P2 ticket sits in `on_hold` for 3 days
**When** it returns to `in_progress`
**Then** `sla_resolve_by` shifts by 3 days and the ticket is not counted as breached for that period.

**AC-9 — Human overrides require a reason**
**Given** an officer changes `category` from `hvac` to `plumbing`
**When** `PATCH /tickets/{id}` is sent without `reason`
**Then** it returns `400` and no change is recorded.

**AC-10 — Prioritisation fairness**
**Given** identical symptoms reported from a student dormitory and from the Rector's office building
**When** the AI analyses both
**Then** the resulting `priority` is identical (at least 20 paired test cases).

**AC-11 — Image privacy**
**Given** an image in which a person's face is clearly visible
**When** the system stores it
**Then** the served file has `redacted = 1` with the face blurred, and no file remains in `_raw/` after 24 hours.

**AC-12 — Reporters see only their own tickets**
**Given** a user with the `reporter` role
**When** they request `GET /tickets/{id}` for someone else's ticket
**Then** it returns `404`, not `403`, so the ticket's existence is not disclosed.

**AC-13 — Images are not directly reachable**
**Given** an unauthenticated user who knows an image file path
**When** they request the file URL directly
**Then** access fails (the file is outside the webroot), and `GET /api/v1/media/{id}` returns `401`.

**AC-14 — Ticket numbers are unique under concurrency**
**Given** 50 concurrent `POST /tickets` requests
**When** all succeed
**Then** all 50 `ticket_no` values are distinct.

---

## 14. Demo Scope (Hackathon)

### Must have

- [ ] Intake via LINE OA with photo + text
- [ ] AI classification: category, priority, summary, with `evidence`
- [ ] Hazard detection forcing P1 + notification
- [ ] Duplicate detection (trigram + Dice)
- [ ] Staff dashboard: job queue, triage queue, assignment
- [ ] Technician LIFF view: accept job, close with `after` photo
- [ ] Repeat-repair analysis over real historical data
- [ ] Complete `ticket_events` audit log

### Should have

- [ ] Automatic face and number-plate blurring
- [ ] Predicted materials list
- [ ] SLA report by team
- [ ] Monthly AI cost dashboard

### Won't have (present as roadmap)

- [ ] Inventory / spare-parts API integration
- [ ] Predictive maintenance
- [ ] Energy-meter correlation to catch abnormally power-hungry equipment
- [ ] Offline mode for technicians in low-signal areas

---

## 15. Integration with Existing Division Systems

| Existing system | Integration approach | Priority |
|---|---|---|
| **Smart Services** (legacy maintenance system) | Import history via `SmartServicesImporter`. If it sits on the same SQL Server instance, query across databases directly; otherwise use a Linked Server or CSV export. | High |
| Asset / inventory register | Import into the `assets` table | Medium |
| **UP DMS** (document system) | Export work orders and monthly reports | Low |
| **Smart Water** | Cross-reference water consumption to corroborate leak reports | Low |
| University SSO | OIDC/SAML for the staff dashboard | High |

**Principle:** every integration goes through a single interface layer in `src/Integration/`, so a downstream system can be swapped without touching the core.

---

## 16. Open Questions

To be answered by the Division of Buildings and Grounds / ICT Centre before development starts:

1. How many historical maintenance records exist, covering how many years, and with which columns?
2. What database does the existing Smart Services run on, and is it on the same SQL Server instance?
3. Where is the authoritative list of building names and standard building codes?
4. How many technician teams exist today, and which zone does each cover?
5. Is there an official SLA already in force? If so, it replaces §4.2.
6. Does the division already have a LINE OA, or must one be created?
7. Will the server be Windows/IIS or Linux/Nginx, and which PHP version?
8. Can the `pdo_sqlsrv` and `imagick` extensions be installed on that server?
9. What retention period does university policy mandate for images and personal data?
10. Can the server make outbound HTTPS calls to the Anthropic and LINE APIs?

---

## 17. Changelog

| Version | Date | Changes |
|---|---|---|
| 2.0 | 2026-08-18 | Rewritten in English. Same technical content as 1.1, restructured to follow the original spec's section order (Goal → Requirements → Tech Stack → Edge Cases) with supporting sections added. |
| 1.1 | 2026-08-18 | Retargeted to PHP + Vanilla JS + SQL Server: SQL Server data types throughout, `job_queue` table + PHP CLI worker replacing Redis/BullMQ, trigram + Dice coefficient replacing pgvector, race-safe ticket numbering, AC-13/AC-14 added. |
| 1.0 | 2026-08-18 | Initial version — replaced the previous TeamBoard Card Feature spec in full. |

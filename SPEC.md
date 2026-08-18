# UP-Fix — ระบบแจ้งซ่อมอัจฉริยะ กองอาคารสถานที่ มหาวิทยาลัยพะเยา

**Spec version:** 1.0
**Status:** Draft for implementation
**Owner:** กองอาคารสถานที่ มหาวิทยาลัยพะเยา
**Last updated:** 2026-08-18

---

## 1. Overview

### 1.1 ปัญหา

ปัจจุบันการแจ้งซ่อมภายในมหาวิทยาลัยกระจายอยู่หลายช่องทาง (โทรศัพท์ LINE ส่วนตัว กระดาษ เดินมาบอกด้วยตัวเอง) ทำให้เกิดปัญหา:

| ปัญหา | ผลที่ตามมา |
|---|---|
| ผู้แจ้งไม่รู้ว่างานที่แจ้งเป็นประเภทไหน | จ่ายงานผิดสาย ต้องส่งต่อ เสียเวลา |
| ผู้แจ้งอธิบายอาการไม่ตรงกับสาเหตุจริง | ช่างไปหน้างานโดยไม่ได้เตรียมอะไหล่ ต้องกลับมาใหม่ |
| ไม่มีการจัดลำดับความเร่งด่วน | งานอันตราย (สายไฟเปลือย ฝ้าถล่ม) ปนอยู่กับงานทั่วไป |
| แจ้งซ้ำจุดเดิมหลายคน | เกิดใบสั่งงานซ้ำซ้อน |
| ไม่มีข้อมูลย้อนหลังที่เป็นระบบ | ของบประมาณโดยไม่มีหลักฐาน |
| ผู้แจ้งไม่รู้สถานะ | โทรตามซ้ำ เพิ่มภาระเจ้าหน้าที่ |

### 1.2 เป้าหมาย

สร้างระบบรับแจ้งซ่อมที่ผู้ใช้ **ถ่ายรูปและพิมพ์ข้อความสั้น ๆ** แล้ว AI ทำงานที่เหลือแทน: จำแนกประเภทงาน ประเมินความเร่งด่วน ระบุตำแหน่ง ตรวจจับงานซ้ำ คาดการณ์วัสดุที่ต้องใช้ และจ่ายงานให้ช่างสายที่ถูกต้อง

### 1.3 Non-goals (ไม่อยู่ในขอบเขต)

ระบุไว้ชัดเจนเพื่อกันขอบเขตบานปลาย:

- ❌ ไม่ทำระบบจัดซื้อ/เบิกจ่ายพัสดุ (เชื่อมต่อเท่านั้น)
- ❌ ไม่ทำระบบประเมินผลงานช่างรายบุคคล (ดู §9.4 ข้อห้ามเชิงจริยธรรม)
- ❌ ไม่ทำ IoT/เซนเซอร์ตรวจจับอัตโนมัติในเฟสนี้
- ❌ ไม่ทำระบบจองห้อง/ยานพาหนะ (มีระบบเดิมอยู่แล้ว)
- ❌ ไม่แทนที่การตัดสินใจของหัวหน้าช่าง — AI เสนอ มนุษย์อนุมัติ
- ❌ ไม่ทำ mobile app แยก (ใช้ LINE OA + Web)

---

## 2. Personas & User Stories

### 2.1 ผู้แจ้ง (นิสิต / อาจารย์ / บุคลากร)

| # | User Story |
|---|---|
| R-1 | ในฐานะนิสิต ฉันต้องการถ่ายรูปจุดชำรุดส่งทาง LINE แล้วจบ โดยไม่ต้องรู้ว่าเป็นงานประเภทไหน |
| R-2 | ในฐานะผู้แจ้ง ฉันต้องการรู้ว่าเรื่องของฉันอยู่ขั้นตอนไหน และคาดว่าจะเสร็จเมื่อไหร่ |
| R-3 | ในฐานะผู้แจ้ง ฉันต้องการรู้ทันทีถ้าเรื่องนี้มีคนแจ้งไปแล้ว จะได้ไม่ต้องแจ้งซ้ำ |

### 2.2 เจ้าหน้าที่ธุรการ / ผู้คัดกรอง (Triage Officer)

| # | User Story |
|---|---|
| T-1 | ในฐานะผู้คัดกรอง ฉันต้องการเห็นงานที่ AI ไม่มั่นใจแยกออกมาเป็นคิวเฉพาะ เพื่อตัดสินใจเอง |
| T-2 | ในฐานะผู้คัดกรอง ฉันต้องการแก้ไขการจำแนกของ AI ได้ และระบบต้องเรียนรู้จากการแก้ไขนั้น |
| T-3 | ในฐานะผู้คัดกรอง ฉันต้องการเห็นงานอันตรายเด้งขึ้นมาก่อนเสมอ |

### 2.3 ช่างภาคสนาม (Technician)

| # | User Story |
|---|---|
| F-1 | ในฐานะช่าง ฉันต้องการเห็นรูปและอาการก่อนไปหน้างาน เพื่อเตรียมอะไหล่ให้ถูก |
| F-2 | ในฐานะช่าง ฉันต้องการปิดงานด้วยการถ่ายรูปหลังซ่อมจากมือถือ |
| F-3 | ในฐานะช่าง ฉันต้องการดูประวัติการซ่อมของอุปกรณ์ตัวนี้ที่ผ่านมา |

### 2.4 หัวหน้างาน / ผู้อำนวยการกอง (Manager)

| # | User Story |
|---|---|
| M-1 | ในฐานะหัวหน้างาน ฉันต้องการเห็นงานค้าง งานเกิน SLA และภาระงานของแต่ละสาย |
| M-2 | ในฐานะผู้อำนวยการ ฉันต้องการรู้ว่าอาคารไหน/อุปกรณ์ไหนซ่อมซ้ำจนควรเปลี่ยนใหม่ |

---

## 3. สถาปัตยกรรมระบบ

```
┌─────────────────┐     ┌─────────────────┐
│   LINE OA       │     │  Web Dashboard  │
│  (ผู้แจ้ง/ช่าง)  │     │ (จนท./ผู้บริหาร) │
└────────┬────────┘     └────────┬────────┘
         │ webhook               │ HTTPS
         └───────────┬───────────┘
                     ▼
         ┌───────────────────────┐
         │   API Layer (Express) │
         │   - Auth / RBAC       │
         │   - Rate limit        │
         │   - Idempotency       │
         └───────────┬───────────┘
                     │
      ┌──────────────┼──────────────┐
      ▼              ▼              ▼
┌───────────┐  ┌───────────┐  ┌───────────┐
│  Job      │  │ PostgreSQL│  │  Object   │
│  Queue    │  │ +pgvector │  │  Storage  │
│ (BullMQ)  │  │           │  │  (S3/MinIO)│
└─────┬─────┘  └───────────┘  └───────────┘
      │
      ▼  (async worker)
┌────────────────────────────────────┐
│      AI Triage Pipeline            │
│  1. PII redaction (เบลอหน้า/ทะเบียน)│
│  2. Image quality gate             │
│  3. Vision classification (Claude) │
│  4. Duplicate detection (embedding)│
│  5. Routing rules (โค้ด ไม่ใช่ LLM) │
└────────────────────────────────────┘
```

**หลักการสำคัญ:** LLM ทำหน้าที่ *เข้าใจภาษาและภาพ* เท่านั้น การตัดสินใจเชิงกฎ (SLA, การจ่ายงาน, การคำนวณต้นทุน) ทำด้วยโค้ดที่ตรวจสอบได้

---

## 4. Data Model

### 4.1 `buildings`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `code` | varchar(20) UNIQUE | เช่น `ICT`, `PKY`, `CE` |
| `name_th` | text | |
| `zone` | varchar(50) | โซนพื้นที่สำหรับจ่ายงานตามระยะทาง |
| `lat`, `lng` | numeric | ใช้จับคู่กับ GPS ของผู้แจ้ง |
| `floors` | int | |
| `gross_area_sqm` | numeric | สำหรับสถิติงานซ่อมต่อพื้นที่ |

### 4.2 `assets` — ทะเบียนอุปกรณ์

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `asset_code` | varchar(50) UNIQUE | เช่น `AC-ICT-1301-02` |
| `building_id` | uuid FK | |
| `floor` | int | |
| `room` | varchar(50) | |
| `category` | enum | ตาม §5.1 |
| `brand`, `model` | text | nullable |
| `installed_at` | date | nullable — ใช้คำนวณอายุ |
| `replacement_cost` | numeric | สำหรับวิเคราะห์ซ่อม vs เปลี่ยน |
| `status` | enum | `active` \| `retired` |

> **หมายเหตุ:** ระบบต้องทำงานได้แม้ `assets` ว่างเปล่า — การผูก ticket กับ asset เป็น optional

### 4.3 `tickets`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `ticket_no` | varchar(20) UNIQUE | รูปแบบ `UPF-YYYYMM-NNNNN` |
| `reporter_channel` | enum | `line` \| `web` \| `phone` \| `walkin` |
| `reporter_ref` | varchar(100) | LINE userId (hashed) หรือรหัสบุคลากร |
| `reporter_display_name` | text | nullable |
| `raw_text` | text | ข้อความดิบที่ผู้แจ้งพิมพ์ |
| `building_id` | uuid FK | nullable ตอนแรก |
| `floor` | int | nullable |
| `room` | varchar(50) | nullable |
| `location_note` | text | เช่น "หน้าห้องน้ำชาย ชั้น 2" |
| `gps_lat`, `gps_lng` | numeric | nullable |
| `asset_id` | uuid FK | nullable |
| `category` | enum | ตาม §5.1 |
| `subcategory` | varchar(50) | |
| `priority` | enum | `P1` \| `P2` \| `P3` \| `P4` |
| `safety_hazard` | boolean | default false |
| `status` | enum | ตาม §4.7 |
| `assigned_team` | varchar(50) | nullable |
| `assigned_to` | uuid FK → technicians | nullable |
| `duplicate_of` | uuid FK → tickets | nullable |
| `sla_respond_by` | timestamptz | |
| `sla_resolve_by` | timestamptz | |
| `on_hold_reason` | text | nullable |
| `on_hold_total_minutes` | int | default 0 — หักออกจากการคิด SLA |
| `created_at`, `updated_at` | timestamptz | |
| `closed_at` | timestamptz | nullable |

**Indexes:** `(status, priority, created_at)`, `(building_id, category, status)`, `(sla_resolve_by) WHERE status NOT IN ('closed','cancelled')`

### 4.4 `ticket_media`

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `ticket_id` | uuid FK | |
| `kind` | enum | `before` \| `after` \| `reference` |
| `storage_key` | text | path ใน object storage |
| `mime_type` | varchar(50) | |
| `bytes` | int | |
| `redacted` | boolean | true = ผ่านการเบลอ PII แล้ว |
| `quality_score` | numeric | 0–1 จาก image quality gate |
| `uploaded_at` | timestamptz | |

> **กฎ:** เก็บเฉพาะไฟล์ที่ `redacted = true` เท่านั้น ไฟล์ต้นฉบับถูกลบทันทีหลังประมวลผล

### 4.5 `ai_classifications` — เก็บทุกครั้งที่ AI วิเคราะห์ (ห้ามเขียนทับ)

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `ticket_id` | uuid FK | |
| `model` | varchar(50) | เช่น `claude-sonnet-4` |
| `prompt_version` | varchar(20) | สำหรับ A/B และ rollback |
| `output` | jsonb | ตาม schema §5.3 |
| `confidence` | numeric | 0–1 |
| `latency_ms` | int | |
| `input_tokens`, `output_tokens` | int | สำหรับคิดต้นทุน |
| `created_at` | timestamptz | |

### 4.6 `ticket_events` — audit log (append-only)

| Column | Type | Notes |
|---|---|---|
| `id` | bigserial PK | |
| `ticket_id` | uuid FK | |
| `actor_type` | enum | `ai` \| `user` \| `system` |
| `actor_id` | varchar(100) | nullable |
| `event_type` | enum | `created` \| `classified` \| `reclassified` \| `assigned` \| `on_site` \| `on_hold` \| `resumed` \| `completed` \| `closed` \| `reopened` \| `cancelled` \| `commented` \| `merged` |
| `payload` | jsonb | ค่าก่อน/หลัง |
| `reason` | text | **บังคับ** เมื่อ event_type = `reclassified` |
| `created_at` | timestamptz | |

### 4.7 Ticket State Machine

```
  new ──► triaging ──┬──► assigned ──► in_progress ──┬──► completed ──► closed
                     │         ▲                     │                    │
                     │         │                     ├──► on_hold ────────┘
                     │         └─────────────────────┘   (รออะไหล่)
                     │
                     ├──► needs_info  (AI/จนท. ขอข้อมูลเพิ่ม)
                     ├──► duplicate   (ผูกกับ ticket เดิม)
                     ├──► rejected    (ไม่ใช่งานกองอาคารฯ)
                     └──► cancelled   (ผู้แจ้งยกเลิก)
```

**กฎการเปลี่ยนสถานะ:**
- `completed → closed` เกิดอัตโนมัติหลัง 72 ชม. หากผู้แจ้งไม่ทักท้วง
- `closed → reopened` ได้ภายใน 14 วันเท่านั้น
- เวลาที่อยู่ใน `on_hold` และ `needs_info` **ไม่นับ** ใน SLA

---

## 5. AI Triage Pipeline

### 5.1 Taxonomy (หมวดหมู่งาน)

| `category` | ตัวอย่าง `subcategory` | ทีมรับผิดชอบ |
|---|---|---|
| `electrical` | `light_out`, `power_outage`, `breaker_trip`, `exposed_wire`, `socket_damaged` | งานสาธารณูปโภค–ไฟฟ้า |
| `plumbing` | `leak`, `clog`, `no_water`, `toilet_broken`, `drain_blocked` | งานสาธารณูปโภค–ประปา |
| `hvac` | `ac_not_cooling`, `ac_water_drip`, `ac_noise`, `ac_remote` | งานสาธารณูปโภค–ปรับอากาศ |
| `structural` | `ceiling_collapse`, `wall_crack`, `door_window`, `tile_broken`, `roof_leak` | งานอาคาร |
| `elevator` | `stuck`, `door_fault`, `noise` | ผู้รับจ้างภายนอก |
| `landscape` | `tree_fallen`, `branch_risk`, `overgrown`, `irrigation` | งานสวนและภูมิทัศน์ |
| `safety` | `street_light_out`, `cctv_fault`, `extinguisher_expired`, `fire_alarm` | งานความปลอดภัย |
| `civil` | `road_pothole`, `manhole_missing`, `walkway_damaged` | งานอาคาร |
| `furniture` | `desk_chair_broken`, `whiteboard`, `cabinet` | งานอาคาร |
| `other` | — | คิวคัดกรองมนุษย์ |

### 5.2 ระดับความเร่งด่วนและ SLA

| Priority | นิยาม | ตัวอย่าง | ตอบรับภายใน | เสร็จภายใน |
|---|---|---|---|---|
| **P1** | อันตรายต่อชีวิตหรือทรัพย์สินร้ายแรง | สายไฟเปลือย ฝ้าถล่ม น้ำรั่วใส่แผงไฟ ลิฟต์ค้างมีคนติด ต้นไม้ล้มขวางถนน แก๊สรั่ว | 15 นาที | 4 ชม. |
| **P2** | ใช้งานพื้นที่/อุปกรณ์ไม่ได้ กระทบการเรียนการสอน | แอร์ห้องเรียนเสีย ไฟดับทั้งชั้น น้ำไม่ไหล | 4 ชม. | 2 วันทำการ |
| **P3** | ใช้งานได้แต่บกพร่อง | หลอดไฟเสีย 1 ดวง ก๊อกน้ำหยด | 1 วันทำการ | 5 วันทำการ |
| **P4** | ความสวยงาม/ไม่เร่งด่วน | สีลอก ป้ายเอียง | 3 วันทำการ | 15 วันทำการ |

> **กฎเหล็ก:** หาก `safety_hazard = true` → บังคับ `priority = P1` เสมอ ไม่ว่า AI จะให้ค่า confidence เท่าไร และต้องส่งแจ้งเตือนหัวหน้างานทันทีผ่าน LINE + SMS

### 5.3 AI Output Schema (บังคับ — validate ด้วย JSON Schema ทุกครั้ง)

```jsonc
{
  "category": "hvac",
  "subcategory": "ac_water_drip",
  "priority": "P2",
  "safety_hazard": false,
  "hazard_reason": null,

  "location": {
    "building_code": "ICT",      // null ถ้าระบุไม่ได้
    "floor": 3,
    "room": "ICT1301",
    "confidence": 0.72
  },

  "asset_guess": {
    "asset_code": "AC-ICT-1301-02",
    "confidence": 0.55
  },

  "summary_th": "แอร์ห้อง ICT1301 มีน้ำหยดจากคอยล์เย็นลงบนฝ้า เกิดคราบน้ำเป็นวงกว้าง",
  "suspected_causes": ["ท่อน้ำทิ้งอุดตัน", "ถาดรองน้ำทิ้งรั่ว"],
  "suggested_materials": [
    { "name": "ท่อ PVC 3/4 นิ้ว", "qty": 1, "unit": "เส้น" }
  ],
  "required_skills": ["hvac"],
  "estimated_duration_min": 90,

  "confidence": 0.81,
  "needs_human_triage": false,
  "evidence": [
    "ภาพแสดงคราบน้ำสีน้ำตาลบนแผ่นฝ้าใต้ตำแหน่งคอยล์เย็น",
    "ข้อความผู้แจ้งระบุว่า 'มีน้ำหยดตอนเปิดแอร์'"
  ],
  "clarifying_question_th": null   // มีค่าเมื่อ needs_human_triage หรือข้อมูลไม่พอ
}
```

**กฎการ validate:**
- ถ้า output ไม่ตรง schema → retry สูงสุด 2 ครั้ง → ถ้ายังไม่ผ่าน ส่งเข้าคิวคัดกรองมนุษย์
- `evidence` ต้องมีอย่างน้อย 1 รายการเสมอ — ห้ามให้โมเดลสรุปโดยไม่อ้างหลักฐาน
- ห้ามโมเดลสร้าง `building_code` หรือ `asset_code` ที่ไม่มีในฐานข้อมูล → ตรวจสอบหลังรับ output ถ้าไม่พบให้ตั้งเป็น `null`

### 5.4 เกณฑ์ความเชื่อมั่น (Confidence Routing)

| ช่วง confidence | การกระทำ |
|---|---|
| `≥ 0.75` | จ่ายงานอัตโนมัติ แจ้งผู้แจ้งทันที |
| `0.50 – 0.74` | จำแนกไว้ก่อน แต่ติดธง `needs_review` ให้ผู้คัดกรองยืนยันภายใน 1 ชม. |
| `< 0.50` | ไม่จ่ายงาน เข้าคิวคัดกรองมนุษย์ + ถามผู้แจ้งกลับด้วย `clarifying_question_th` |
| ใด ๆ + `safety_hazard = true` | จ่ายเป็น P1 ทันที **และ** แจ้งมนุษย์ให้ยืนยันคู่ขนาน |

### 5.5 การตรวจจับงานซ้ำ (Duplicate Detection)

ทำงาน 2 ชั้น:

1. **ชั้นกฎ (เร็ว):** ticket ที่ยังเปิดอยู่ ใน `building_id` + `floor` + `category` เดียวกัน ภายใน 14 วัน → เป็น candidate
2. **ชั้นความหมาย:** คำนวณ embedding ของ `summary_th` เทียบ cosine similarity กับ candidates
   - `≥ 0.88` → ผูกเป็น duplicate อัตโนมัติ แจ้งผู้แจ้งว่า "เรื่องนี้มีคนแจ้งแล้ว หมายเลข UPF-…  สถานะปัจจุบันคือ…"
   - `0.75 – 0.87` → ถามผู้แจ้งยืนยันว่าใช่เรื่องเดียวกันหรือไม่
   - `< 0.75` → สร้าง ticket ใหม่

> **สำคัญ:** การผูก duplicate ต้อง **ย้อนกลับได้** เสมอ (`POST /tickets/:id/unmerge`) เพราะการรวมงานผิดทำให้เรื่องจริงหายไป

### 5.6 การจ่ายงาน (Routing) — ทำด้วยโค้ด ไม่ใช่ LLM

ลำดับการเลือกช่าง:
1. กรองช่างที่มี skill ตรงกับ `required_skills` และสถานะ `available`
2. กรองช่างที่รับผิดชอบ `zone` ของอาคารนั้น
3. เรียงตามจำนวนงานค้าง (น้อยไปมาก)
4. เรียงตามงานที่เคยซ่อม `asset_id` เดียวกันมาก่อน (ให้ความสำคัญกับความต่อเนื่อง)
5. ถ้าไม่มีช่างว่าง → เข้าคิวรอ + แจ้งหัวหน้างานเมื่อ P1/P2 รอเกิน 30 นาที

---

## 6. API Specification

**Base URL:** `/api/v1`
**Auth:** Bearer JWT (ยกเว้น `/webhooks/line` ที่ใช้ LINE signature validation)

### 6.1 สร้าง ticket

```
POST /api/v1/tickets
Content-Type: multipart/form-data
Idempotency-Key: <uuid>   (บังคับ)
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `text` | string | ⚠️ | ต้องมี `text` หรือ `images` อย่างน้อยหนึ่งอย่าง |
| `images[]` | file | ⚠️ | สูงสุด 5 ไฟล์ ไฟล์ละ ≤ 10 MB (jpeg/png/heic/webp) |
| `building_code` | string | ✗ | ถ้ารู้ |
| `floor` | int | ✗ | |
| `room` | string | ✗ | |
| `gps_lat`, `gps_lng` | number | ✗ | |
| `channel` | enum | ✓ | `line` \| `web` |

**Response `201 Created`** (คืนทันที ไม่รอ AI):

```json
{
  "ticket_no": "UPF-202608-00147",
  "id": "3f9a…",
  "status": "triaging",
  "message_th": "รับเรื่องแล้ว กำลังวิเคราะห์ ระบบจะแจ้งผลภายใน 1 นาที",
  "poll_url": "/api/v1/tickets/3f9a…"
}
```

### 6.2 Endpoints ทั้งหมด

| Method | Path | Role | คำอธิบาย |
|---|---|---|---|
| `POST` | `/tickets` | any | สร้างเรื่องแจ้งซ่อม |
| `GET` | `/tickets/:id` | reporter/staff | ดูรายละเอียด + timeline |
| `GET` | `/tickets` | staff | ค้นหา: `status`, `priority`, `building`, `category`, `assigned_to`, `overdue=true`, `page`, `limit` |
| `PATCH` | `/tickets/:id` | triage/manager | แก้ไขการจำแนก — **ต้องส่ง `reason`** |
| `POST` | `/tickets/:id/assign` | triage/manager | จ่ายงานให้ช่าง |
| `POST` | `/tickets/:id/events` | staff | บันทึกเหตุการณ์ (`on_site`, `on_hold`, `completed`, `commented`) |
| `POST` | `/tickets/:id/merge` | triage | ผูกเป็นงานซ้ำ |
| `POST` | `/tickets/:id/unmerge` | triage | ยกเลิกการผูก |
| `POST` | `/tickets/:id/reopen` | reporter/staff | เปิดใหม่ (ภายใน 14 วัน) |
| `GET` | `/assets/:id/history` | staff | ประวัติซ่อมของอุปกรณ์ |
| `GET` | `/analytics/repeat-repairs` | manager | รายการ "ซ่อมวน" + ต้นทุนสะสม |
| `GET` | `/analytics/sla` | manager | สถิติ SLA รายทีม/รายอาคาร |
| `POST` | `/webhooks/line` | — | LINE Messaging API webhook |
| `GET` | `/healthz` | — | health check |

### 6.3 รูปแบบ Error มาตรฐาน

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

| HTTP | `code` | เมื่อไหร่ |
|---|---|---|
| 400 | `INVALID_PAYLOAD` | payload ผิดรูปแบบ |
| 401 | `UNAUTHORIZED` | ไม่มี/ token หมดอายุ |
| 403 | `FORBIDDEN` | บทบาทไม่มีสิทธิ์ |
| 404 | `TICKET_NOT_FOUND` | |
| 409 | `DUPLICATE_REQUEST` | `Idempotency-Key` ซ้ำ |
| 409 | `INVALID_STATE_TRANSITION` | เปลี่ยนสถานะไม่ถูกตาม state machine |
| 413 | `FILE_TOO_LARGE` | ไฟล์ > 10 MB |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | ไม่ใช่ภาพที่รองรับ |
| 422 | `TICKET_EMPTY_INPUT` | ไม่มีทั้งข้อความและรูป |
| 429 | `RATE_LIMITED` | เกินโควตา (§10.3) |
| 503 | `AI_UNAVAILABLE` | AI ล่ม — **ticket ยังถูกสร้าง** สถานะ `triaging` |

---

## 7. LINE OA Flow

```
ผู้ใช้ส่งรูป + ข้อความ
        │
        ▼
[ตอบทันที] "รับเรื่องแล้ว UPF-202608-00147 กำลังวิเคราะห์…"
        │
        ▼ (< 60 วินาที)
[ตอบผลวิเคราะห์]
  "🔧 งานปรับอากาศ – แอร์มีน้ำหยด
   📍 อาคาร ICT ชั้น 3 ห้อง ICT1301
   ⚡ ความเร่งด่วน: ปานกลาง (P2)
   👷 มอบหมาย: ทีมปรับอากาศ
   ⏱️ คาดว่าแล้วเสร็จภายใน 20 ส.ค. 2569
   [ปุ่ม: ดูสถานะ] [ปุ่ม: ไม่ถูกต้อง แก้ไข]"
        │
        ▼ อัปเดตอัตโนมัติเมื่อเปลี่ยนสถานะสำคัญ
   assigned → in_progress → completed
        │
        ▼
[ขอความเห็น] "งานเสร็จแล้ว พอใจไหม? 👍 / 👎"
```

**กรณีข้อมูลไม่พอ** ระบบถามกลับด้วย quick reply เช่น
`"ไม่แน่ใจว่าเป็นอาคารไหน กรุณาเลือก: [ICT] [PKY] [CE] [อื่น ๆ] [ส่งตำแหน่ง GPS]"`

**กรณีตรวจพบงานซ้ำ**
`"เรื่องนี้ตรงกับ UPF-202608-00131 ที่แจ้งเมื่อ 2 วันก่อน สถานะ: ช่างกำลังดำเนินการ — [เรื่องเดียวกัน] [คนละเรื่อง]"`

---

## 8. Edge Cases & Error Handling

### 8.1 อินพุตจากผู้แจ้ง

| กรณี | พฤติกรรมที่ต้องการ |
|---|---|
| มีรูป ไม่มีข้อความ | ✅ ดำเนินการปกติ วิเคราะห์จากรูปอย่างเดียว |
| มีข้อความ ไม่มีรูป | ✅ ดำเนินการ แต่ลด confidence ลง 0.15 และขอรูปเพิ่ม |
| ไม่มีทั้งสองอย่าง | ❌ `422 TICKET_EMPTY_INPUT` |
| ข้อความยาวเกิน 5,000 ตัวอักษร | ตัดที่ 5,000 + บันทึก flag `text_truncated` |
| รูปเบลอ/มืดเกินไป (`quality_score < 0.3`) | ขอถ่ายใหม่ พร้อมคำแนะนำ "กรุณาถ่ายให้เห็นจุดชำรุดชัดเจนและเปิดไฟ" |
| รูปไม่เกี่ยวข้อง (เซลฟี่ สกรีนช็อต มีม) | ตอบสุภาพว่าไม่พบจุดชำรุดในภาพ ขอภาพใหม่ **ไม่สร้าง ticket** |
| รูปมีใบหน้าคน/ป้ายทะเบียนรถ | เบลออัตโนมัติก่อนบันทึก (§9.1) |
| ข้อความสะกดผิด/ใช้ภาษาถิ่น/คำแสลงช่าง | LLM ต้องรับมือได้ — ครอบคลุมใน test set |
| ข้อความเป็นภาษาอังกฤษ | ✅ รองรับ ตอบกลับเป็นภาษาเดียวกับที่ผู้แจ้งใช้ |
| แจ้งเรื่องที่ไม่ใช่งานกองอาคารฯ (เน็ตล่ม คอมพิวเตอร์เสีย) | สถานะ `rejected` + แนะนำช่องทางที่ถูกต้อง (ศูนย์ ICT) |
| ข้อความหยาบคาย/ร้องเรียนบุคคล | สร้าง ticket ตามปกติแต่ไม่ส่งข้อความดิบเข้า LLM ในส่วนที่เป็นการกล่าวหาบุคคล + แจ้งหัวหน้างาน |
| ส่งซ้ำ ๆ รัว ๆ (spam) | rate limit §10.3 → `429` |

### 8.2 ตำแหน่งและอุปกรณ์

| กรณี | พฤติกรรม |
|---|---|
| ระบุอาคารไม่ได้ แต่มี GPS | หาอาคารที่ใกล้ที่สุดในรัศมี 80 ม. → เสนอให้ยืนยัน |
| GPS อยู่นอกพื้นที่มหาวิทยาลัย | เพิกเฉย GPS + ถามอาคารจากผู้แจ้ง |
| ชื่ออาคารที่ AI ตอบไม่มีในฐานข้อมูล | ตั้ง `building_id = null` + เข้าคิวคัดกรอง (ห้ามสร้างอาคารใหม่อัตโนมัติ) |
| ห้องที่ระบุไม่มีในอาคารนั้น | เก็บไว้ใน `location_note` แทน ไม่ใส่ `room` |
| `assets` ยังไม่มีข้อมูลเลย | ระบบทำงานได้ปกติ — `asset_id = null` |

### 8.3 ระบบและโครงสร้างพื้นฐาน

| กรณี | พฤติกรรม |
|---|---|
| AI API timeout / ล่ม | ticket ถูกสร้างแล้วเสมอ → retry 3 ครั้ง (exponential backoff 2s/8s/30s) → ถ้ายังล้มเหลว เข้าคิวคัดกรองมนุษย์ + แจ้งผู้แจ้งว่า "อยู่ระหว่างตรวจสอบโดยเจ้าหน้าที่" |
| AI ตอบไม่ตรง JSON schema | retry 2 ครั้งพร้อม error feedback → ล้มเหลว → คิวมนุษย์ |
| LINE webhook ส่งซ้ำ (LINE retry) | ใช้ `webhookEventId` เป็น idempotency key |
| อัปโหลดรูปสำเร็จบางไฟล์ | ticket ยังสร้างได้ + บันทึก `partial_upload` + แจ้งผู้แจ้งว่ารูปที่ N ล้มเหลว |
| Object storage เต็ม/ล่ม | ticket สร้างได้จากข้อความ + คิวอัปโหลดใหม่ภายหลัง |
| ฐานข้อมูลล่ม | `503` + ข้อความภาษาไทย ห้ามคืน stack trace |
| ช่างปิดงานโดยไม่มีรูป after | ❌ ปฏิเสธสำหรับ P1/P2 (`INVALID_STATE_TRANSITION`) / ⚠️ เตือนแต่อนุญาตสำหรับ P3/P4 |
| งานรออะไหล่ | `on_hold` + ระบุ `on_hold_reason` → นาฬิกา SLA หยุดเดิน |
| P1 เกิดนอกเวลาราชการ | แจ้งเวรรักษาการณ์ + หัวหน้างานทันทีทุกช่องทาง |
| ticket ค้างเกิน SLA | escalate อัตโนมัติไปหัวหน้างานที่ 100% ของเวลา SLA และผู้อำนวยการที่ 150% |
| ผู้แจ้งลบข้อความใน LINE | ข้อมูลใน ticket ยังอยู่ (เป็นบันทึกราชการ) |

---

## 9. ความปลอดภัย ธรรมาภิบาล และจริยธรรม AI

### 9.1 PDPA และข้อมูลส่วนบุคคล

- **เบลออัตโนมัติ** ใบหน้าบุคคลและป้ายทะเบียนรถในทุกภาพก่อนบันทึกลง storage ภาพต้นฉบับถูกลบภายใน 24 ชม.
- LINE `userId` **เก็บเป็น hash** (SHA-256 + salt) ไม่เก็บดิบ
- ไม่ส่งชื่อ–สกุล เบอร์โทร อีเมล ของผู้แจ้งเข้า LLM — ส่งเฉพาะข้อความอาการและภาพที่ผ่านการเบลอแล้ว
- นโยบายเก็บรักษา: ภาพเก็บ 2 ปี, ข้อมูล ticket เก็บ 5 ปี (ตามระเบียบงานสารบรรณ), หลังจากนั้นทำ anonymize
- ผู้แจ้งขอลบข้อมูลส่วนบุคคลของตนได้ โดย ticket ยังคงอยู่ในรูปแบบไม่ระบุตัวตน
- ต้องมีหน้าแจ้งนโยบายความเป็นส่วนตัวและขอความยินยอมตอนผูก LINE OA ครั้งแรก

### 9.2 ความปลอดภัยของระบบ

- RBAC 5 บทบาท: `reporter`, `technician`, `triage`, `manager`, `admin`
  - `reporter` เห็นเฉพาะ ticket ของตนเอง
  - `technician` เห็นเฉพาะงานที่ได้รับมอบหมาย + ประวัติ asset
  - `triage`/`manager` เห็นทั้งหมด แต่ export ข้อมูลต้องบันทึก audit
- JWT อายุสั้น (15 นาที) + refresh token
- Rate limit ทุก endpoint, signature validation สำหรับ LINE webhook
- เข้ารหัสข้อมูลระหว่างส่ง (TLS 1.2+) และขณะพัก (at-rest encryption)
- Object storage ไม่เปิด public — เข้าถึงผ่าน pre-signed URL อายุ 15 นาที
- `ticket_events` เป็น append-only ห้ามลบหรือแก้ไข

### 9.3 ความโปร่งใสและการป้องกัน Hallucination

| มาตรการ | รายละเอียด |
|---|---|
| **บังคับอ้างหลักฐาน** | ทุก classification ต้องมี `evidence` อย่างน้อย 1 รายการ ที่อ้างถึงสิ่งที่เห็นในภาพหรือข้อความจริง |
| **ไม่ให้ LLM คิดเลข** | SLA, การจ่ายงาน, ต้นทุนสะสม, สถิติ คำนวณด้วยโค้ดทั้งหมด |
| **ไม่ให้ LLM สร้างรหัส** | `building_code` / `asset_code` ต้องตรวจสอบกับฐานข้อมูล ไม่พบ = null |
| **แสดง confidence ต่อผู้ใช้** | หน้าจอเจ้าหน้าที่แสดงระดับความมั่นใจและปุ่ม "AI วิเคราะห์ผิด" ทุกครั้ง |
| **ยอมรับว่าไม่รู้** | เมื่อ confidence < 0.5 ระบบต้องพูดว่าไม่แน่ใจและถามกลับ ห้ามเดา |
| **มนุษย์ตัดสินใจสุดท้าย** | AI จัดลำดับและเสนอ แต่หัวหน้าช่างอนุมัติ/ปรับได้เสมอ พร้อมบันทึกเหตุผล |

### 9.4 ข้อห้ามเชิงจริยธรรม (บังคับใช้ในโค้ด)

- 🚫 **ห้ามใช้ข้อมูลในระบบประเมินผลปฏิบัติงานช่างเป็นรายบุคคล** — API วิเคราะห์ต้อง aggregate ระดับทีมเท่านั้น ไม่มี endpoint ที่คืนสถิติรายบุคคล
- 🚫 ห้ามจัดลำดับความเร่งด่วนตามตำแหน่งของผู้แจ้ง (อธิการบดีกับนิสิตต้องได้ P เดียวกันสำหรับอาการเดียวกัน) — ให้เขียน unit test ยืนยันข้อนี้
- 🚫 ห้ามใช้ภาพจากระบบไปทำอย่างอื่นนอกเหนือจากงานซ่อมบำรุง
- ✅ ต้องทดสอบอคติ: อาการเดียวกันจากอาคารนิสิต (หอพัก) กับอาคารบริหาร ต้องได้ priority เท่ากัน

---

## 10. Non-Functional Requirements

### 10.1 ประสิทธิภาพ (แทนคำว่า "ต้องเร็ว")

| ตัวชี้วัด | เป้าหมาย |
|---|---|
| `POST /tickets` p95 (ไม่รวม AI) | < 400 ms |
| AI triage เสร็จ p95 | < 30 วินาที นับจากสร้าง ticket |
| AI triage เสร็จ p99 | < 60 วินาที |
| Query dashboard p95 | < 800 ms ที่ข้อมูล 100,000 tickets |
| รองรับการแจ้ง | 500 เรื่อง/วัน, burst 50 เรื่อง/นาที |

### 10.2 ความพร้อมใช้งาน

- Uptime ≥ 99% ในช่วง 07:00–19:00 น. วันทำการ
- **การรับแจ้งต้องไม่ล้มเหลวแม้ AI ล่ม** — ระบบ degrade ไปเป็นคิวคัดกรองมนุษย์
- RPO ≤ 24 ชม. (backup รายวัน), RTO ≤ 4 ชม.

### 10.3 Rate Limits

| ขอบเขต | โควตา |
|---|---|
| ต่อ `reporter_ref` | 10 tickets / ชม., 30 / วัน |
| ต่อ IP (web) | 60 requests / นาที |
| อัปโหลดไฟล์ | 20 ไฟล์ / ชม. / ผู้ใช้ |

### 10.4 ต้นทุน

- ต้นทุน AI ต่อ 1 ticket ≤ 0.60 บาท (ประเมินจาก 1 ภาพ + ข้อความสั้น)
- ใช้ Haiku สำหรับงานคัดกรองเบื้องต้น/image quality gate, Sonnet สำหรับการวิเคราะห์เต็ม
- ใช้ prompt caching สำหรับ taxonomy และรายชื่ออาคาร (ส่วนที่คงที่)
- บันทึก token usage ทุกครั้งใน `ai_classifications` เพื่อทำรายงานต้นทุนรายเดือน

### 10.5 การรองรับภาษา

- UI และข้อความตอบกลับ: ภาษาไทยเป็นหลัก
- รองรับผู้แจ้งภาษาอังกฤษ (ตอบกลับภาษาเดียวกับที่ผู้แจ้งใช้)
- วันที่แสดงเป็น พ.ศ. ในหน้าจอผู้ใช้ แต่เก็บใน DB เป็น ISO 8601 UTC

---

## 11. คุณภาพโมเดลและการวัดผล

### 11.1 ชุดข้อมูลทดสอบ

- สร้าง labeled test set จากประวัติแจ้งซ่อมจริงย้อนหลัง **≥ 300 รายการ** ที่ผู้เชี่ยวชาญ (หัวหน้าช่าง) ติดป้ายกำกับแล้ว
- แบ่ง 70/15/15 (prompt tuning / dev / held-out test)
- ต้องมีตัวอย่างของทุก `category` อย่างน้อย 15 รายการ และตัวอย่าง P1 อย่างน้อย 20 รายการ

### 11.2 เกณฑ์ผ่าน (Acceptance Thresholds)

| ตัวชี้วัด | เป้าหมายขั้นต่ำ |
|---|---|
| ความถูกต้องของ `category` | ≥ 85% |
| **Recall ของ P1 (งานอันตราย)** | **≥ 95%** — พลาดงานอันตรายเป็นความเสี่ยงที่ยอมรับไม่ได้ |
| Precision ของ P1 | ≥ 60% (ยอมให้เตือนเกินได้ ดีกว่าพลาด) |
| ความถูกต้องของ `building_code` (เมื่อผู้แจ้งไม่ระบุ) | ≥ 70% |
| Precision ของ duplicate detection | ≥ 80% |
| อัตราที่ต้องคัดกรองโดยมนุษย์ | ≤ 20% ของ tickets ทั้งหมด |
| อัตราที่เจ้าหน้าที่ต้องแก้ไขการจำแนก | ≤ 15% |

### 11.3 การติดตามหลังใช้งานจริง

- แดชบอร์ดคุณภาพโมเดล: แสดงอัตราการแก้ไขโดยมนุษย์รายสัปดาห์ แยกตาม category
- ทุกครั้งที่เจ้าหน้าที่กด "AI วิเคราะห์ผิด" → บันทึกเป็นตัวอย่างสำหรับปรับ prompt รอบถัดไป
- เปรียบเทียบ prompt version ด้วย `prompt_version` ใน `ai_classifications`

---

## 12. Acceptance Criteria

> ทุกข้อต้องมี automated test ครอบคลุม

### AC-1 — สร้าง ticket จากรูปอย่างเดียว
**Given** ผู้ใช้ส่งภาพหลอดไฟกะพริบทาง LINE โดยไม่พิมพ์ข้อความ
**When** ระบบรับเรื่อง
**Then** ต้องคืน `201` พร้อม `ticket_no` ภายใน 400 ms และภายใน 60 วินาที ticket ต้องมี `category = "electrical"` และมี `evidence` อย่างน้อย 1 รายการ

### AC-2 — งานอันตรายต้องเป็น P1 เสมอ
**Given** ภาพแสดงสายไฟเปลือยห้อยลงมาในทางเดิน
**When** AI วิเคราะห์และคืน `safety_hazard = true` พร้อม `priority = "P3"`
**Then** ระบบต้อง override เป็น `P1` และส่งแจ้งเตือนหัวหน้างานภายใน 60 วินาที

### AC-3 — ไม่มีทั้งข้อความและรูป
**Given** คำขอที่ไม่มี `text` และไม่มี `images`
**When** เรียก `POST /tickets`
**Then** ต้องคืน `422` พร้อม `code = "TICKET_EMPTY_INPUT"` และ **ไม่สร้าง** record ใด ๆ

### AC-4 — AI ล่มต้องไม่ทำให้แจ้งซ่อมไม่ได้
**Given** AI API คืน error ทุกครั้ง
**When** ผู้ใช้ส่งเรื่องแจ้งซ่อม
**Then** ticket ต้องถูกสร้างสำเร็จ สถานะ `triaging` เข้าคิวคัดกรองมนุษย์ และผู้แจ้งได้รับข้อความยืนยันการรับเรื่อง

### AC-5 — ตรวจจับงานซ้ำ
**Given** มี ticket เปิดอยู่ "แอร์ห้อง ICT1301 ไม่เย็น"
**When** ผู้ใช้อีกคนแจ้ง "ห้อง ICT1301 ร้อนมาก แอร์เสีย"
**Then** ระบบต้องเสนอว่าเป็นเรื่องเดียวกัน และไม่จ่ายงานใหม่ให้ช่างจนกว่าจะมีการยืนยัน

### AC-6 — ไม่สร้างรหัสอาคารที่ไม่มีจริง
**Given** AI คืน `building_code = "XYZ"` ซึ่งไม่มีในฐานข้อมูล
**When** ระบบบันทึกผล
**Then** `building_id` ต้องเป็น `null` และ ticket ต้องเข้าคิวคัดกรองมนุษย์

### AC-7 — ปิดงาน P1 ต้องมีรูป after
**Given** ticket ระดับ P1 สถานะ `in_progress`
**When** ช่างกดปิดงานโดยไม่แนบภาพ `after`
**Then** ต้องคืน `409 INVALID_STATE_TRANSITION` และสถานะไม่เปลี่ยน

### AC-8 — SLA หยุดเดินเมื่อรออะไหล่
**Given** ticket P2 อยู่สถานะ `on_hold` เป็นเวลา 3 วัน
**When** เปลี่ยนกลับเป็น `in_progress`
**Then** `sla_resolve_by` ต้องถูกเลื่อนออกไป 3 วัน และ ticket ต้องไม่ถูกนับว่าเกิน SLA ในช่วงนั้น

### AC-9 — ทุกการแก้ไขของมนุษย์ต้องมีเหตุผล
**Given** เจ้าหน้าที่แก้ `category` จาก `hvac` เป็น `plumbing`
**When** ส่ง `PATCH /tickets/:id` โดยไม่มี field `reason`
**Then** ต้องคืน `400` และไม่บันทึกการแก้ไข

### AC-10 — ความเป็นธรรมของการจัดลำดับ
**Given** อาการเดียวกันทุกประการ แจ้งจากหอพักนิสิต กับ จากอาคารสำนักงานอธิการบดี
**When** AI วิเคราะห์ทั้งสองเรื่อง
**Then** `priority` ที่ได้ต้องเท่ากัน (ทดสอบด้วย paired test cases อย่างน้อย 20 คู่)

### AC-11 — ความเป็นส่วนตัวของภาพ
**Given** ภาพที่มีใบหน้าบุคคลปรากฏชัด
**When** ระบบบันทึกภาพ
**Then** ไฟล์ใน storage ต้องมี `redacted = true` และใบหน้าถูกเบลอ และไม่มีไฟล์ต้นฉบับหลงเหลือหลัง 24 ชม.

### AC-12 — ผู้แจ้งเห็นเฉพาะเรื่องของตน
**Given** ผู้ใช้บทบาท `reporter`
**When** เรียก `GET /tickets/:id` ของ ticket ที่ผู้อื่นแจ้ง
**Then** ต้องคืน `404` (ไม่ใช่ `403` เพื่อไม่เปิดเผยว่ามี ticket นั้นอยู่)

---

## 13. Tech Stack

| ส่วน | เทคโนโลยี | เหตุผล |
|---|---|---|
| Runtime | Node.js 20 LTS | ทีมคุ้นเคย, ecosystem พร้อม |
| API Framework | Express 4 + Zod (validation) | เบา ตรงไปตรงมา |
| Database | PostgreSQL 16 + `pgvector` | ต้องการ relational + vector similarity ในที่เดียว |
| Queue | BullMQ + Redis | งาน AI ต้องเป็น async |
| Object Storage | MinIO (dev) / S3-compatible (prod) | เก็บภาพ |
| AI | Claude (Sonnet = วิเคราะห์เต็ม, Haiku = คัดกรองเบื้องต้น) | multimodal + ควบคุม output ด้วย JSON schema ได้ |
| Frontend | Next.js 14 + Tailwind | dashboard + mobile web สำหรับช่าง |
| Chat channel | LINE Messaging API | adoption สูงสุดในไทย ไม่ต้องให้ใครโหลดแอปใหม่ |
| Testing | Vitest + Supertest + Testcontainers | integration test กับ DB จริง |
| Observability | pino (structured log) + OpenTelemetry | |

### 13.1 Environment Variables

```bash
DATABASE_URL=postgres://...
REDIS_URL=redis://...
S3_ENDPOINT=            # MinIO/S3
S3_BUCKET=up-fix-media
S3_ACCESS_KEY=
S3_SECRET_KEY=
ANTHROPIC_API_KEY=
AI_MODEL_PRIMARY=claude-sonnet-4
AI_MODEL_FAST=claude-haiku-4
AI_PROMPT_VERSION=v1
LINE_CHANNEL_SECRET=
LINE_CHANNEL_ACCESS_TOKEN=
JWT_SECRET=
REPORTER_ID_SALT=       # สำหรับ hash LINE userId
CONFIDENCE_AUTO_ASSIGN=0.75
CONFIDENCE_HUMAN_TRIAGE=0.50
DUPLICATE_SIMILARITY_AUTO=0.88
DUPLICATE_SIMILARITY_ASK=0.75
```

### 13.2 โครงสร้างโปรเจกต์

```
up-fix/
├── SPEC.md
├── docker-compose.yml          # postgres + redis + minio
├── src/
│   ├── api/
│   │   ├── routes/             # tickets, assets, analytics, webhooks
│   │   ├── middleware/         # auth, rbac, idempotency, ratelimit
│   │   └── errors.ts           # error catalog §6.3
│   ├── domain/
│   │   ├── ticket-state.ts     # state machine §4.7
│   │   ├── sla.ts              # คำนวณ SLA §5.2
│   │   └── routing.ts          # จ่ายงาน §5.6
│   ├── ai/
│   │   ├── prompts/v1/         # แยกตาม version
│   │   ├── schema.ts           # JSON schema §5.3
│   │   ├── classify.ts
│   │   ├── redact.ts           # เบลอ PII §9.1
│   │   └── duplicate.ts        # §5.5
│   ├── workers/                # BullMQ consumers
│   └── db/
│       ├── migrations/
│       └── seed/               # อาคาร มพ. + taxonomy
├── web/                        # Next.js dashboard
└── tests/
    ├── integration/
    ├── fixtures/               # ภาพตัวอย่างสำหรับ test
    └── eval/                   # labeled test set §11.1
```

---

## 14. ขอบเขตสำหรับเดโม (Hackathon Scope)

### ต้องมี (Must have)

- [ ] รับแจ้งผ่าน LINE OA ด้วยรูป + ข้อความ
- [ ] AI จำแนกประเภท + ความเร่งด่วน + สรุปอาการ พร้อม evidence
- [ ] ตรวจจับงานอันตราย → P1 + แจ้งเตือน
- [ ] ตรวจจับงานซ้ำ
- [ ] Dashboard เจ้าหน้าที่: คิวงาน คิวคัดกรอง จ่ายงาน
- [ ] หน้าจอช่าง (mobile web): รับงาน ปิดงานพร้อมรูป after
- [ ] วิเคราะห์ "ซ่อมวน" จากประวัติจริงย้อนหลัง
- [ ] Audit log ครบทุก action

### ควรมี (Should have)

- [ ] เบลอใบหน้า/ทะเบียนอัตโนมัติ
- [ ] คาดการณ์วัสดุที่ต้องใช้
- [ ] รายงาน SLA รายทีม
- [ ] แดชบอร์ดต้นทุน AI รายเดือน

### ไว้เฟสถัดไป (Won't have — พูดเป็น roadmap)

- [ ] เชื่อม API ระบบพัสดุ/คลังอะไหล่
- [ ] พยากรณ์การชำรุดล่วงหน้า (predictive maintenance)
- [ ] เชื่อมข้อมูลมิเตอร์พลังงานเพื่อจับอุปกรณ์กินไฟผิดปกติ
- [ ] แอปพลิเคชันเฉพาะสำหรับช่าง (offline mode)

---

## 15. การเชื่อมต่อระบบเดิมของกองอาคารสถานที่

ระบบต้องออกแบบให้เชื่อมต่อกับระบบที่กองอาคารฯ ใช้อยู่ได้ ผ่าน adapter layer:

| ระบบเดิม | รูปแบบการเชื่อมต่อ | ลำดับความสำคัญ |
|---|---|---|
| Smart Services (ระบบแจ้งซ่อมเดิม) | นำเข้าประวัติย้อนหลัง (CSV/DB) + sync สองทางในอนาคต | สูง |
| ทะเบียนครุภัณฑ์/พัสดุ | นำเข้าเป็น `assets` | กลาง |
| UP DMS (ระบบเอกสาร) | ส่งออกใบสั่งงาน/รายงานประจำเดือน | ต่ำ |
| Smart Water | อ้างอิงข้อมูลการใช้น้ำเพื่อยืนยันเหตุท่อรั่ว | ต่ำ |
| ระบบยืนยันตัวตนมหาวิทยาลัย (SSO) | OIDC สำหรับ dashboard เจ้าหน้าที่ | สูง |

**หลักการ:** ทุกการเชื่อมต่อผ่าน adapter interface เดียว (`src/integrations/*`) เพื่อให้เปลี่ยนระบบปลายทางได้โดยไม่กระทบ core

---

## 16. Open Questions

คำถามที่ต้องได้คำตอบจากกองอาคารสถานที่ก่อนเริ่มพัฒนา:

1. ประวัติแจ้งซ่อมย้อนหลังมีกี่รายการ ครอบคลุมกี่ปี และมีคอลัมน์อะไรบ้าง?
2. รายชื่ออาคารและรหัสอาคารมาตรฐานของมหาวิทยาลัยมีที่ไหน?
3. โครงสร้างทีมช่างปัจจุบันแบ่งเป็นกี่สาย แต่ละสายรับผิดชอบโซนไหน?
4. มีการกำหนด SLA อย่างเป็นทางการอยู่แล้วหรือไม่ ถ้ามีให้ใช้ของจริงแทน §5.2
5. ทะเบียนครุภัณฑ์ (แอร์ ลิฟต์ ปั๊มน้ำ) อยู่ในรูปแบบใด และเชื่อมต่อได้หรือไม่?
6. LINE OA ของกองอาคารฯ มีอยู่แล้วหรือต้องสร้างใหม่?
7. นโยบายการเก็บภาพและข้อมูลส่วนบุคคลของมหาวิทยาลัยกำหนดระยะเวลาไว้เท่าไร?
8. ระบบจะโฮสต์บนเซิร์ฟเวอร์ของมหาวิทยาลัยหรือ cloud ภายนอก?

---

## 17. Changelog

| Version | Date | Changes |
|---|---|---|
| 1.0 | 2026-08-18 | ฉบับแรก — แทนที่สเปกเดิม (TeamBoard Card Feature) ทั้งฉบับ |

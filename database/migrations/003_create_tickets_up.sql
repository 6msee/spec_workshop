-- SPEC.md 5.3 tickets
--
-- NOTE (spec gap, recorded per plan instruction): SPEC.md 5.3 declares
-- `assigned_to` as an FK to a `technicians` table, but SPEC.md section 5
-- never defines `technicians` anywhere in this document. `technicians` is
-- created in Phase 3 (see ROADMAP.md); until then `assigned_to` is a plain
-- UNIQUEIDENTIFIER NULL column with NO foreign key constraint. The FK is
-- added by a Phase 3 migration once `technicians` exists.
CREATE TABLE dbo.tickets (
    id                      UNIQUEIDENTIFIER NOT NULL DEFAULT NEWSEQUENTIALID() PRIMARY KEY,
    ticket_no               NVARCHAR(20)  NOT NULL,
    reporter_channel        NVARCHAR(20)  NOT NULL,
    reporter_ref            NVARCHAR(100) NOT NULL,
    reporter_display_name   NVARCHAR(200) NULL,
    raw_text                NVARCHAR(MAX) NULL,
    building_id             UNIQUEIDENTIFIER NULL,
    floor                   INT NULL,
    room                    NVARCHAR(50)  NULL,
    location_note           NVARCHAR(500) NULL,
    gps_lat                 DECIMAL(10,7) NULL,
    gps_lng                 DECIMAL(10,7) NULL,
    asset_id                UNIQUEIDENTIFIER NULL,
    category                NVARCHAR(30)  NULL,
    subcategory             NVARCHAR(50)  NULL,
    priority                CHAR(2)       NULL,
    safety_hazard           BIT NOT NULL DEFAULT 0,
    status                  NVARCHAR(20)  NOT NULL DEFAULT 'triaging',
    assigned_team           NVARCHAR(50)  NULL,
    assigned_to             UNIQUEIDENTIFIER NULL, -- FK to technicians deferred to Phase 3, see note above
    duplicate_of            UNIQUEIDENTIFIER NULL,
    sla_respond_by          DATETIME2(0) NULL,
    sla_resolve_by          DATETIME2(0) NULL,
    on_hold_reason          NVARCHAR(500) NULL,
    on_hold_total_minutes   INT NOT NULL DEFAULT 0,
    created_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    updated_at              DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    closed_at               DATETIME2(0) NULL,
    CONSTRAINT UQ_tickets_ticket_no UNIQUE (ticket_no),
    CONSTRAINT FK_tickets_building FOREIGN KEY (building_id) REFERENCES dbo.buildings (id),
    CONSTRAINT FK_tickets_asset FOREIGN KEY (asset_id) REFERENCES dbo.assets (id),
    CONSTRAINT FK_tickets_duplicate_of FOREIGN KEY (duplicate_of) REFERENCES dbo.tickets (id),
    CONSTRAINT CK_tickets_reporter_channel CHECK (reporter_channel IN ('line', 'web', 'phone', 'walkin')),
    CONSTRAINT CK_tickets_category CHECK (category IS NULL OR category IN (
        'electrical', 'plumbing', 'hvac', 'structural', 'elevator',
        'landscape', 'safety', 'civil', 'furniture', 'other'
    )),
    CONSTRAINT CK_tickets_priority CHECK (priority IS NULL OR priority IN ('P1', 'P2', 'P3', 'P4')),
    CONSTRAINT CK_tickets_status CHECK (status IN (
        'new', 'triaging', 'assigned', 'in_progress', 'on_hold', 'needs_info',
        'completed', 'closed', 'duplicate', 'rejected', 'cancelled', 'reopened'
    ))
);

CREATE INDEX IX_tickets_queue    ON dbo.tickets(status, priority, created_at);
CREATE INDEX IX_tickets_building ON dbo.tickets(building_id, category, status);
CREATE INDEX IX_tickets_assignee ON dbo.tickets(assigned_to, status);
-- NOTE: SPEC.md 5.3's filtered index literally reads
-- "WHERE status NOT IN ('closed', 'cancelled')" but SQL Server's filtered
-- index predicate parser rejects NOT IN (empirically confirmed against
-- this instance: "Incorrect syntax near 'NOT'."). The AND-chained <> form
-- below is semantically identical for exactly these two excluded values
-- and is accepted by the filtered-index predicate parser.
CREATE INDEX IX_tickets_sla_open ON dbo.tickets(sla_resolve_by)
    WHERE status <> 'closed' AND status <> 'cancelled';

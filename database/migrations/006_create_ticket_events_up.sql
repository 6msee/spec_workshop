-- SPEC.md 5.6 ticket_events (append-only audit log) — AUDIT-01
CREATE TABLE dbo.ticket_events (
    id          BIGINT IDENTITY(1,1) PRIMARY KEY,
    ticket_id   UNIQUEIDENTIFIER NOT NULL,
    actor_type  NVARCHAR(10) NOT NULL,
    actor_id    NVARCHAR(100) NULL,
    event_type  NVARCHAR(20) NOT NULL,
    payload     NVARCHAR(MAX) NULL,
    reason      NVARCHAR(500) NULL,
    created_at  DATETIME2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
    CONSTRAINT FK_ticket_events_ticket FOREIGN KEY (ticket_id) REFERENCES dbo.tickets (id),
    CONSTRAINT CK_ticket_events_actor_type CHECK (actor_type IN ('ai', 'user', 'system')),
    CONSTRAINT CK_ticket_events_event_type CHECK (event_type IN (
        'created', 'classified', 'reclassified', 'assigned', 'on_site', 'on_hold',
        'resumed', 'completed', 'closed', 'reopened', 'cancelled', 'commented',
        'merged', 'unmerged'
    )),
    CONSTRAINT CK_ticket_events_payload_json CHECK (payload IS NULL OR ISJSON(payload) = 1),
    -- RESEARCH.md Pitfall 5: SPEC.md 5.6 states in prose that `reason` is
    -- required for reclassification but expresses no constraint for it.
    -- Close that gap here so the rule is DB-enforced, not app-convention-only.
    CONSTRAINT CK_ticket_events_reclassify_reason CHECK (event_type <> 'reclassified' OR reason IS NOT NULL)
);

CREATE INDEX IX_ticket_events_ticket ON dbo.ticket_events(ticket_id, created_at, id);

-- AUDIT-01: the application's own database login is powerless to edit or
-- remove audit history, enforced at the database-privilege level, not by
-- application convention. SPEC.md 5.6's prose form ("Grant DENY UPDATE,
-- DELETE ON ticket_events to the application database user") is not valid
-- T-SQL as literally written -- DENY is a statement, not a clause of GRANT
-- (RESEARCH.md Pattern 3). This corrected form must be the final statement
-- of this migration so a fresh environment built purely by
-- bin/migrate.php never lacks the guarantee.
DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app;

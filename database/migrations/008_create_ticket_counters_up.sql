-- SPEC.md 5.9 ticket_no generation — backing counter table
CREATE TABLE dbo.ticket_counters (
    period   NVARCHAR(6) NOT NULL PRIMARY KEY,
    last_no  INT NOT NULL
);

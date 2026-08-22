<?php

declare(strict_types=1);

namespace UpFix\Domain;

use UpFix\Db\Connection;

/**
 * Race-safe ticket_no allocation (SPEC.md 5.9), format UPF-YYYYMM-NNNNN.
 *
 * Closes the gap in SPEC.md's own snippet (which only comments "-- if no
 * row exists, INSERT starting at 1" without real SQL for it, per
 * RESEARCH.md Pitfall 2): a single atomic MERGE statement, guarded by
 * HOLDLOCK, both increments an existing period row and creates a missing
 * one in the same round trip, so the first ticket of a new calendar month
 * cannot fail or race under concurrent requests.
 *
 * Never derive the number from MAX(ticket_no) — SPEC.md 5.9 forbids it
 * explicitly because it races under concurrent submissions.
 */
final class TicketNumber
{
    public static function next(Connection $db, string $period): string
    {
        $stmt = $db->run(
            <<<SQL
            MERGE dbo.ticket_counters WITH (HOLDLOCK) AS t
            USING (SELECT :period AS period) AS s
            ON t.period = s.period
            WHEN MATCHED THEN
                UPDATE SET last_no = t.last_no + 1
            WHEN NOT MATCHED THEN
                INSERT (period, last_no) VALUES (s.period, 1)
            OUTPUT inserted.last_no;
            SQL,
            ['period' => $period],
        );

        $row = $stmt->fetch();
        $lastNo = (int) $row['last_no'];

        return sprintf('UPF-%s-%05d', $period, $lastNo);
    }
}

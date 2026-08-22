<?php

declare(strict_types=1);

namespace UpFix\Tests\Feature;

use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use UpFix\Db\Connection;
use UpFix\Domain\EventLog;
use UpFix\Http\Controllers\TicketController;
use UpFix\Http\Request;
use UpFix\Http\Response;
use UpFix\Support\Env;

/**
 * AUDIT-01: proves the application's own database login (upfix_app) is
 * provably unable to modify or remove any dbo.ticket_events row, and that
 * a failed audit write rolls back the enclosing ticket-creation
 * transaction rather than leaving an orphaned ticket with no history.
 */
final class TicketEventsImmutabilityTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../..');
        $this->db = Connection::fromEnv();
    }

    // No tearDown() cleanup: dbo.ticket_events is genuinely append-only
    // (DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app -- this is
    // exactly what this test class proves), and a tickets row cannot be
    // deleted once FK_ticket_events_ticket rows reference it. Rows created
    // here are permanent, matching real production behaviour; every
    // assertion below keys off its own fresh UUID ticket id, so
    // accumulated rows from prior runs never interfere.

    #[Test]
    public function creatingATicketYieldsExactlyOneCreatedEvent(): void
    {
        $controller = new TicketController($this->db);
        $request = new Request(
            'POST',
            '/api/v1/tickets',
            ['text' => 'test fault', 'channel' => 'web', 'reporter_ref' => 'immutability-test'],
            [],
        );
        $response = new Response();

        ob_start();
        $controller->create($request, $response, 'req_immutability-1');
        $body = (string) ob_get_clean();
        $decoded = json_decode($body, true, flags: JSON_THROW_ON_ERROR);

        $rows = $this->db->run(
            "SELECT event_type FROM dbo.ticket_events WHERE ticket_id = :ticket_id AND event_type = 'created'",
            ['ticket_id' => $decoded['id']],
        )->fetchAll();

        self::assertCount(1, $rows);
    }

    #[Test]
    public function updateAgainstTicketEventsIsRejectedAndLeavesTheRowUnchanged(): void
    {
        $ticketId = $this->insertBareTicket();
        EventLog::record($this->db, $ticketId, 'system', null, 'created', ['x' => 1]);

        $before = $this->fetchLatestEventFor($ticketId);

        try {
            $this->db->run(
                "UPDATE dbo.ticket_events SET reason = 'tampered' WHERE ticket_id = :ticket_id",
                ['ticket_id' => $ticketId],
            );
            self::fail('UPDATE against dbo.ticket_events unexpectedly succeeded');
        } catch (PDOException) {
            // expected
        }

        $after = $this->fetchLatestEventFor($ticketId);
        self::assertSame($before, $after);
    }

    #[Test]
    public function deleteAgainstTicketEventsIsRejectedAndLeavesTheRowCountUnchanged(): void
    {
        $ticketId = $this->insertBareTicket();
        EventLog::record($this->db, $ticketId, 'system', null, 'created', ['x' => 1]);

        $countBefore = $this->countEventsFor($ticketId);

        try {
            $this->db->run(
                'DELETE FROM dbo.ticket_events WHERE ticket_id = :ticket_id',
                ['ticket_id' => $ticketId],
            );
            self::fail('DELETE against dbo.ticket_events unexpectedly succeeded');
        } catch (PDOException) {
            // expected
        }

        self::assertSame($countBefore, $this->countEventsFor($ticketId));
    }

    #[Test]
    public function aRawReclassifiedInsertWithNullReasonIsRejectedByTheCheckConstraint(): void
    {
        $ticketId = $this->insertBareTicket();

        $this->expectException(PDOException::class);

        $this->db->run(
            <<<SQL
            INSERT INTO dbo.ticket_events (ticket_id, actor_type, event_type, payload, reason)
            VALUES (:ticket_id, 'user', 'reclassified', N'{}', NULL)
            SQL,
            ['ticket_id' => $ticketId],
        );
    }

    #[Test]
    public function twoEventsInTheSameSecondPersistAndReturnInAscendingIdOrder(): void
    {
        $ticketId = $this->insertBareTicket();

        EventLog::record($this->db, $ticketId, 'system', null, 'created', ['seq' => 1]);
        EventLog::record($this->db, $ticketId, 'system', null, 'commented', ['seq' => 2]);

        $rows = $this->db->run(
            'SELECT id, event_type FROM dbo.ticket_events WHERE ticket_id = :ticket_id ORDER BY created_at ASC, id ASC',
            ['ticket_id' => $ticketId],
        )->fetchAll();

        self::assertCount(2, $rows);
        self::assertSame('created', $rows[0]['event_type']);
        self::assertSame('commented', $rows[1]['event_type']);
        self::assertLessThan((int) $rows[1]['id'], (int) $rows[0]['id']);
    }

    #[Test]
    public function aFailedEventInsertInsideTheIntakeTransactionLeavesZeroRowsInBothTables(): void
    {
        $ticketId = Uuid::uuid4()->toString();
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $this->db->run(
                <<<SQL
                INSERT INTO dbo.tickets (
                    id, ticket_no, reporter_channel, reporter_ref, status, created_at, updated_at
                ) VALUES (
                    :id, :ticket_no, 'web', 'immutability-test', 'triaging', SYSUTCDATETIME(), SYSUTCDATETIME()
                )
                SQL,
                ['id' => $ticketId, 'ticket_no' => 'UPF-999999-99999'],
            );

            // Force the event write to fail: an invalid event_type is
            // rejected by the CHECK constraint, simulating any failure
            // inside the same transaction as the tickets INSERT.
            EventLog::record($this->db, $ticketId, 'system', null, 'created', []);
            $this->db->run(
                "INSERT INTO dbo.ticket_events (ticket_id, actor_type, event_type, payload) VALUES (:id, 'system', 'not_a_real_type', N'{}')",
                ['id' => $ticketId],
            );

            $pdo->commit();
            self::fail('Expected the forced-invalid event insert to throw');
        } catch (PDOException) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        $ticketCount = $this->db->run(
            'SELECT COUNT(*) AS c FROM dbo.tickets WHERE id = :id',
            ['id' => $ticketId],
        )->fetch()['c'];

        $eventCount = $this->db->run(
            'SELECT COUNT(*) AS c FROM dbo.ticket_events WHERE ticket_id = :id',
            ['id' => $ticketId],
        )->fetch()['c'];

        self::assertSame(0, (int) $ticketCount);
        self::assertSame(0, (int) $eventCount);
    }

    private function insertBareTicket(): string
    {
        $ticketId = Uuid::uuid4()->toString();
        $ticketNo = 'UPF-' . gmdate('Ym') . '-' . str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT);

        $this->db->run(
            <<<SQL
            INSERT INTO dbo.tickets (
                id, ticket_no, reporter_channel, reporter_ref, status, created_at, updated_at
            ) VALUES (
                :id, :ticket_no, 'web', 'immutability-test', 'triaging', SYSUTCDATETIME(), SYSUTCDATETIME()
            )
            SQL,
            ['id' => $ticketId, 'ticket_no' => $ticketNo],
        );

        return $ticketId;
    }

    /** @return array<string, mixed>|false */
    private function fetchLatestEventFor(string $ticketId): array|false
    {
        return $this->db->run(
            'SELECT TOP 1 id, reason, payload FROM dbo.ticket_events WHERE ticket_id = :ticket_id ORDER BY id DESC',
            ['ticket_id' => $ticketId],
        )->fetch();
    }

    private function countEventsFor(string $ticketId): int
    {
        return (int) $this->db->run(
            'SELECT COUNT(*) AS c FROM dbo.ticket_events WHERE ticket_id = :ticket_id',
            ['ticket_id' => $ticketId],
        )->fetch()['c'];
    }
}

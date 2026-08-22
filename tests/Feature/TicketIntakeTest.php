<?php

declare(strict_types=1);

namespace UpFix\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UpFix\Db\Connection;
use UpFix\Http\Controllers\TicketController;
use UpFix\Http\Request;
use UpFix\Http\Response;
use UpFix\Support\Env;

final class TicketIntakeTest extends TestCase
{
    private Connection $db;

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../..');
        $this->db = Connection::fromEnv();
    }

    protected function tearDown(): void
    {
        // Clean up any ticket rows this test created so repeated runs
        // start from a known state; ticket_counters is intentionally left
        // alone (shared, monotonic per period, other tests may depend on it).
        // Children first -- FK_ticket_events_ticket prevents deleting a
        // tickets row while ticket_events rows still reference it (Task 3
        // wired the 'created' event into the same intake transaction).
        $this->db->run(
            "DELETE e FROM dbo.ticket_events e JOIN dbo.tickets t ON t.id = e.ticket_id WHERE t.reporter_ref = 'feature-test'",
        );
        $this->db->run("DELETE FROM dbo.tickets WHERE reporter_ref = 'feature-test'");
    }

    #[Test]
    public function creatingATicketReturns201WithAWellFormedTicketNo(): void
    {
        $controller = new TicketController($this->db);
        $request = new Request(
            'POST',
            '/api/v1/tickets',
            ['text' => 'แอร์ห้อง ICT1301 ไม่เย็น', 'channel' => 'web', 'reporter_ref' => 'feature-test'],
            [],
        );
        $response = new Response();

        ob_start();
        $controller->create($request, $response, 'req_test-1');
        $body = ob_get_clean();

        $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression('/^UPF-\d{6}-\d{5}$/', $decoded['ticket_no']);
        self::assertSame('triaging', $decoded['status']);
        self::assertArrayHasKey('id', $decoded);
        self::assertArrayHasKey('message_th', $decoded);
        self::assertSame("/api/v1/tickets/{$decoded['id']}", $decoded['poll_url']);

        $row = $this->db->run(
            'SELECT ticket_no, raw_text, reporter_channel FROM dbo.tickets WHERE id = :id',
            ['id' => $decoded['id']],
        )->fetch();

        self::assertNotFalse($row);
        self::assertSame($decoded['ticket_no'], $row['ticket_no']);
        self::assertSame('web', $row['reporter_channel']);
    }

    #[Test]
    public function thaiTextRoundTripsByteIdenticallyThroughNvarcharMax(): void
    {
        $originalText = 'แอร์ห้อง ICT1301 มีน้ำหยดจากคอยล์เย็นลงบนฝ้า เกิดคราบน้ำเป็นวงกว้าง';

        $controller = new TicketController($this->db);
        $request = new Request(
            'POST',
            '/api/v1/tickets',
            ['text' => $originalText, 'channel' => 'web', 'reporter_ref' => 'feature-test'],
            [],
        );
        $response = new Response();

        ob_start();
        $controller->create($request, $response, 'req_test-2');
        $body = ob_get_clean();
        $decoded = json_decode((string) $body, true, flags: JSON_THROW_ON_ERROR);

        $row = $this->db->run(
            'SELECT raw_text FROM dbo.tickets WHERE id = :id',
            ['id' => $decoded['id']],
        )->fetch();

        self::assertSame($originalText, $row['raw_text']);
    }
}

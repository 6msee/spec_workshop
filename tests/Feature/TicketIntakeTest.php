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

    // No tearDown() cleanup: as of Task 3, dbo.ticket_events is genuinely
    // append-only (DENY UPDATE, DELETE ON dbo.ticket_events TO upfix_app),
    // and a tickets row cannot be deleted once FK_ticket_events_ticket
    // rows reference it -- attempting to delete either from this login is
    // now correctly rejected, exactly as AUDIT-01 requires. Test rows
    // created here are permanent, matching real production behaviour;
    // each test's assertions key off its own fresh UUID ticket id, so
    // accumulated rows from prior runs never interfere.

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

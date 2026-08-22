<?php

declare(strict_types=1);

namespace UpFix\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use UpFix\Db\Connection;
use UpFix\Domain\TicketNumber;
use UpFix\Support\Env;

final class TicketNumberTest extends TestCase
{
    private Connection $db;

    // Deliberately out-of-band test periods (year 0000) rather than the
    // real current calendar period: TicketController::create() allocates
    // against gmdate('Ym') (today's real period), and dbo.tickets.ticket_no
    // is UNIQUE -- reusing a real-looking period here would let this test's
    // full-table truncation of dbo.ticket_counters collide with ticket_no
    // values real/feature-test tickets already hold for the current month.
    private const string PERIOD_A = '000001';
    private const string PERIOD_B = '000002';

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../..');
        $this->db = Connection::fromEnv();
        $this->db->run('DELETE FROM dbo.ticket_counters WHERE period IN (:a, :b)', [
            'a' => self::PERIOD_A,
            'b' => self::PERIOD_B,
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->run('DELETE FROM dbo.ticket_counters WHERE period IN (:a, :b)', [
            'a' => self::PERIOD_A,
            'b' => self::PERIOD_B,
        ]);
    }

    #[Test]
    public function formatMatchesSpecPattern(): void
    {
        $ticketNo = TicketNumber::next($this->db, self::PERIOD_A);

        self::assertMatchesRegularExpression('/^UPF-\d{6}-\d{5}$/', $ticketNo);
    }

    #[Test]
    public function sequentialCallsIncrementWithinThePeriod(): void
    {
        $first = TicketNumber::next($this->db, self::PERIOD_A);
        $second = TicketNumber::next($this->db, self::PERIOD_A);

        self::assertSame('UPF-000001-00001', $first);
        self::assertSame('UPF-000001-00002', $second);
    }

    #[Test]
    public function succeedsAgainstACompletelyEmptyCountersTable(): void
    {
        // setUp() already deleted any row for PERIOD_B -- this is the
        // RESEARCH.md Pitfall 2 case: the first request of a brand new
        // period must not fail or assume a row already exists.
        $ticketNo = TicketNumber::next($this->db, self::PERIOD_B);

        self::assertSame('UPF-000002-00001', $ticketNo);
    }
}

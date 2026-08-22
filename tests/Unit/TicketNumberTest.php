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

    protected function setUp(): void
    {
        Env::load(__DIR__ . '/../..');
        $this->db = Connection::fromEnv();
        $this->db->run('DELETE FROM dbo.ticket_counters');
    }

    protected function tearDown(): void
    {
        $this->db->run('DELETE FROM dbo.ticket_counters');
    }

    #[Test]
    public function formatMatchesSpecPattern(): void
    {
        $ticketNo = TicketNumber::next($this->db, '202608');

        self::assertMatchesRegularExpression('/^UPF-\d{6}-\d{5}$/', $ticketNo);
    }

    #[Test]
    public function sequentialCallsIncrementWithinThePeriod(): void
    {
        $first = TicketNumber::next($this->db, '202608');
        $second = TicketNumber::next($this->db, '202608');

        self::assertSame('UPF-202608-00001', $first);
        self::assertSame('UPF-202608-00002', $second);
    }

    #[Test]
    public function succeedsAgainstACompletelyEmptyCountersTable(): void
    {
        // setUp() already truncated dbo.ticket_counters to empty — this is
        // the RESEARCH.md Pitfall 2 case: the first request of a brand new
        // period must not fail or assume a row already exists.
        $ticketNo = TicketNumber::next($this->db, '202609');

        self::assertSame('UPF-202609-00001', $ticketNo);
    }
}

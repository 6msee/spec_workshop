<?php

declare(strict_types=1);

namespace UpFix\Http\Controllers;

use Ramsey\Uuid\Uuid;
use UpFix\Db\Connection;
use UpFix\Domain\EventLog;
use UpFix\Domain\TicketNumber;
use UpFix\Http\Request;
use UpFix\Http\Response;

/**
 * POST /api/v1/tickets — the walking-skeleton intake path. One request,
 * one transaction: allocate ticket_no, insert the tickets row, record the
 * append-only `created` event, commit.
 *
 * Media, empty-input rejection, and job enqueue are deliberately out of
 * this task — plans 01-02 and 01-03 expand this same path.
 */
final class TicketController
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function create(Request $request, Response $response, string $requestId): void
    {
        $text = $request->input('text');
        $channel = $request->input('channel') ?? 'web';
        // building_code is read per the field contract but building_id
        // resolution (lookup against dbo.buildings, nearest-match by GPS,
        // "never auto-create" rule) is out of scope for this tracer task.
        $request->input('building_code');
        $floor = $request->input('floor');
        $room = $request->input('room');
        $gpsLat = $request->input('gps_lat');
        $gpsLng = $request->input('gps_lng');
        $reporterRef = $request->input('reporter_ref') ?? 'anonymous';

        if ($text !== null && mb_strlen($text) > 5000) {
            $text = mb_substr($text, 0, 5000);
        }

        $period = gmdate('Ym');
        $ticketId = Uuid::uuid4()->toString();

        [$ticketNo, $status] = $this->db->withDeadlockRetry(function () use (
            $text,
            $channel,
            $floor,
            $room,
            $gpsLat,
            $gpsLng,
            $reporterRef,
            $period,
            $ticketId,
        ): array {
            $pdo = $this->db->pdo();
            $pdo->beginTransaction();

            try {
                $ticketNo = TicketNumber::next($this->db, $period);
                $status = 'triaging';

                $this->db->run(
                    <<<SQL
                    INSERT INTO dbo.tickets (
                        id, ticket_no, reporter_channel, reporter_ref, raw_text,
                        floor, room, gps_lat, gps_lng, status, created_at, updated_at
                    ) VALUES (
                        :id, :ticket_no, :reporter_channel, :reporter_ref, :raw_text,
                        :floor, :room, :gps_lat, :gps_lng, :status, SYSUTCDATETIME(), SYSUTCDATETIME()
                    )
                    SQL,
                    [
                        'id' => $ticketId,
                        'ticket_no' => $ticketNo,
                        'reporter_channel' => $channel,
                        'reporter_ref' => $reporterRef,
                        'raw_text' => $text,
                        'floor' => $floor !== null ? (int) $floor : null,
                        'room' => $room,
                        'gps_lat' => $gpsLat,
                        'gps_lng' => $gpsLng,
                        'status' => $status,
                    ],
                );

                // Same transaction as the tickets INSERT above -- a failed
                // audit write aborts the ticket rather than producing a
                // ticket with no history (AUDIT-01).
                EventLog::record(
                    $this->db,
                    $ticketId,
                    'system',
                    null,
                    'created',
                    ['ticket_no' => $ticketNo, 'channel' => $channel],
                );

                $pdo->commit();

                return [$ticketNo, $status];
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                throw $e;
            }
        });

        $response->json([
            'ticket_no' => $ticketNo,
            'id' => $ticketId,
            'status' => $status,
            'message_th' => 'รับเรื่องแล้ว กำลังวิเคราะห์ ระบบจะแจ้งผลภายใน 1 นาที',
            'poll_url' => "/api/v1/tickets/{$ticketId}",
        ], 201);
    }
}

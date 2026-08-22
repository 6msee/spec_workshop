<?php

declare(strict_types=1);

namespace UpFix\Domain;

use UpFix\Db\Connection;

/**
 * The ONLY write path into dbo.ticket_events, for this and every future
 * phase. It never issues an UPDATE or DELETE against the table and
 * exposes no method that could — corrections are recorded as new
 * `reclassified` events. The database-level guarantee (DENY UPDATE,
 * DELETE ON dbo.ticket_events, migration 006) is the enforcement
 * mechanism; this class's narrow public surface is belt-and-suspenders
 * at the application layer (AUDIT-01).
 *
 * $actorId carries an internal staff id or the salted-hash `reporter_ref`
 * ONLY. Raw LINE userIds, phone numbers, and email addresses must never
 * appear in $actorId or anywhere in $payload (SPEC.md 10.1).
 */
final class EventLog
{
    public const array EVENT_TYPES = [
        'created', 'classified', 'reclassified', 'assigned', 'on_site', 'on_hold',
        'resumed', 'completed', 'closed', 'reopened', 'cancelled', 'commented',
        'merged', 'unmerged',
    ];

    /**
     * @param array<string, mixed> $payload
     *
     * @throws \InvalidArgumentException when $eventType is unknown, or when
     *         $eventType is 'reclassified' with an empty $reason (belt-and-
     *         suspenders alongside the CK_ticket_events_reclassify_reason
     *         CHECK constraint)
     */
    public static function record(
        Connection $db,
        string $ticketId,
        string $actorType,
        ?string $actorId,
        string $eventType,
        array $payload = [],
        ?string $reason = null,
    ): void {
        if (!in_array($eventType, self::EVENT_TYPES, true)) {
            throw new \InvalidArgumentException("Unknown ticket event type: {$eventType}");
        }

        if ($eventType === 'reclassified' && ($reason === null || trim($reason) === '')) {
            throw new \InvalidArgumentException('reason is required when event_type is reclassified');
        }

        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $db->run(
            <<<SQL
            INSERT INTO dbo.ticket_events (ticket_id, actor_type, actor_id, event_type, payload, reason)
            VALUES (:ticket_id, :actor_type, :actor_id, :event_type, :payload, :reason)
            SQL,
            [
                'ticket_id' => $ticketId,
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'event_type' => $eventType,
                'payload' => $encodedPayload,
                'reason' => $reason,
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace UpFix\Domain;

/**
 * SPEC.md 4.1 work-type taxonomy and 5.8 status values — the single source
 * of truth every SQL CHECK constraint in this project mirrors and the
 * frontend JSON is generated from.
 */
final class Taxonomy
{
    public const array CATEGORIES = [
        'electrical', 'plumbing', 'hvac', 'structural', 'elevator',
        'landscape', 'safety', 'civil', 'furniture', 'other',
    ];

    public const array PRIORITIES = ['P1', 'P2', 'P3', 'P4'];

    public const array STATUSES = [
        'new', 'triaging', 'assigned', 'in_progress', 'on_hold', 'needs_info',
        'completed', 'closed', 'duplicate', 'rejected', 'cancelled', 'reopened',
    ];

    public const array REPORTER_CHANNELS = ['line', 'web', 'phone', 'walkin'];

    public static function isCategory(string $value): bool
    {
        return in_array($value, self::CATEGORIES, true);
    }

    public static function isStatus(string $value): bool
    {
        return in_array($value, self::STATUSES, true);
    }

    public static function isPriority(string $value): bool
    {
        return in_array($value, self::PRIORITIES, true);
    }

    public static function isReporterChannel(string $value): bool
    {
        return in_array($value, self::REPORTER_CHANNELS, true);
    }
}

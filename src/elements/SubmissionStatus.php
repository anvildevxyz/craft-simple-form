<?php

namespace fabianhaef\simpleform\elements;

/**
 * The read states a {@see Submission} moves through.
 *
 * Single source of truth for the `readStatus` column values: the migration's
 * enum definition, the CP status filter/counters, and the status-cycle order
 * all derive from here, so adding or renaming a status is a one-place change.
 */
final class SubmissionStatus
{
    public const NEW = 'new';
    public const READ = 'read';
    public const ARCHIVED = 'archived';

    /**
     * Every status, in cycle/display order (the CP toggle advances through
     * this list and wraps around).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::NEW,
            self::READ,
            self::ARCHIVED,
        ];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}

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
    public const SPAM = 'spam';

    /**
     * The statuses in the CP toggle cycle, in order (the toggle advances through
     * this list and wraps around). SPAM is intentionally excluded — it's set by
     * the spam check, not by manual cycling.
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

    /**
     * Every valid `readStatus` value, including SPAM. Mirrors the migration's
     * enum and gates {@see isValid()}.
     *
     * @return list<string>
     */
    public static function allValid(): array
    {
        return [...self::all(), self::SPAM];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::allValid(), true);
    }
}

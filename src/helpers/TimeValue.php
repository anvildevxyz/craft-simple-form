<?php

namespace anvildev\simpleform\helpers;

/**
 * Parsing, validation, and normalization for a scalar time-of-day (`HH:MM`, 24h)
 * independent of any date.
 *
 * Factored out of {@see \anvildev\simpleform\fields\TimeFieldType} so the
 * Date & Time field type can reuse the exact same time-component handling — the
 * `<input type="time">` value shape and the seconds-tolerant normalization are
 * identical whether the time stands alone or is combined with a date.
 *
 * @since 2.15.0
 */
final class TimeValue
{
    /**
     * Whether the value is a well-formed 24-hour clock time. Accepts both the
     * bare `HH:MM` an `<input type="time">` posts and the `HH:MM:SS` a
     * seconds-enabled control (or a programmatic caller) may send.
     */
    public static function isValid(mixed $value): bool
    {
        return is_string($value) && preg_match('~^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$~', trim($value)) === 1;
    }

    /**
     * Coerce a valid time to the canonical `HH:MM` shape (dropping any seconds
     * component), or return `null` when the value is empty or unparseable. The
     * caller decides whether `null` is an error (required) or an accepted empty.
     */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (!self::isValid($value)) {
            return null;
        }

        return substr($value, 0, 5);
    }
}

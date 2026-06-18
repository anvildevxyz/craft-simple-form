<?php

namespace fabianhaef\simpleform\integrations;

/**
 * The lifecycle statuses of an integration dispatch attempt
 * (`simpleform_integration_logs.status`). Mirrors the holder style of
 * {@see \fabianhaef\simpleform\elements\SubmissionStatus} and
 * {@see \fabianhaef\simpleform\mcp\Scopes}. The migration keeps its own literals
 * (migrations must stay self-contained).
 */
final class DispatchStatus
{
    public const PENDING = 'pending';
    public const SUCCESS = 'success';
    public const FAILED = 'failed';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::PENDING, self::SUCCESS, self::FAILED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}

<?php

namespace fabianhaef\simpleform\mcp;

/**
 * The capability scopes an MCP token can carry.
 *
 * Scopes are the unit of authorization for MCP tools: every tool declares the
 * single scope it requires, and a request is only allowed to invoke a tool when
 * its token's scope set contains that scope (see {@see McpServer}). This is
 * deny-by-default — a token grants nothing it was not explicitly issued.
 *
 * Only the scopes actually backed by a shipped tool are listed here. The wider
 * surface (submissions read/export) is intentionally deferred to later slices
 * (#64) so we never advertise a capability we cannot yet enforce against real
 * data.
 */
final class Scopes
{
    /**
     * Read access to the plugin's form definitions (schema + metadata).
     *
     * Backs the `list_forms` tool. Deliberately scoped to *form structure*
     * only; it never grants access to stored submission data.
     */
    public const FORMS_MANAGE = 'forms:manage';

    /**
     * Read access to stored submissions. Reserved for a later slice (#64);
     * declared here so token issuance and the settings UI can offer it, but no
     * tool consumes it yet.
     */
    public const SUBMISSIONS_READ = 'submissions:read';

    /**
     * Bulk export of submissions. Reserved for a later slice (#64).
     */
    public const SUBMISSIONS_EXPORT = 'submissions:export';

    /**
     * Every scope that may be granted to a token, in display order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::FORMS_MANAGE,
            self::SUBMISSIONS_READ,
            self::SUBMISSIONS_EXPORT,
        ];
    }

    /**
     * Human-readable label for a scope, for the settings UI / logs.
     */
    public static function label(string $scope): string
    {
        return match ($scope) {
            self::FORMS_MANAGE => 'Manage forms (read form definitions)',
            self::SUBMISSIONS_READ => 'Read submissions (reserved — no tool yet)',
            self::SUBMISSIONS_EXPORT => 'Export submissions (reserved — no tool yet)',
            default => $scope,
        };
    }

    /**
     * Whether the given string is a scope this plugin recognises. Used to
     * reject unknown scopes at token-creation time so a typo can never widen
     * (or silently void) a grant.
     */
    public static function isValid(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }
}

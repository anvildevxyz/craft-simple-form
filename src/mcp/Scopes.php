<?php

namespace anvildev\simpleform\mcp;

/**
 * The capability scopes an MCP token can carry.
 *
 * Scopes are the unit of authorization for MCP tools: every tool declares the
 * single scope it requires, and a request is only allowed to invoke a tool when
 * its token's scope set contains that scope (see {@see McpServer}). This is
 * deny-by-default — a token grants nothing it was not explicitly issued.
 *
 * Submission read/export are DISTINCT scopes from form management (privacy
 * default): a forms:manage token grants nothing over submission data, so a
 * forms-only integration can never read or export submissions.
 */
final class Scopes
{
    /**
     * Manage the plugin's form definitions (read + create/update/delete forms
     * and their fields).
     *
     * Backs the form-management tools (list/get/create/update/delete_form,
     * add/update/reorder/delete_field). Deliberately scoped to *form structure*
     * only; it never grants access to stored submission data.
     */
    public const FORMS_MANAGE = 'forms:manage';

    /**
     * Read access to stored submissions. Backs query_submissions,
     * get_submission and submission_stats. Distinct from forms:manage so
     * submission data access is independently controllable.
     */
    public const SUBMISSIONS_READ = 'submissions:read';

    /**
     * Bulk export of submissions. Backs export_submissions. A token must hold
     * THIS scope specifically to export — submissions:read alone is not enough.
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
            self::FORMS_MANAGE => 'Manage forms (read & write form definitions and fields)',
            self::SUBMISSIONS_READ => 'Read submissions (query, view, stats)',
            self::SUBMISSIONS_EXPORT => 'Export submissions (CSV / JSON)',
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

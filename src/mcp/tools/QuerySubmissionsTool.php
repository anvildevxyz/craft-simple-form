<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\SubmissionQueryBuilder;

/**
 * MCP tool: query submissions with filters and paging.
 *
 * Reads through the existing {@see \fabianhaef\simpleform\elements\Submission}
 * element query (with eager-loaded forms, per #56). Gated behind the distinct
 * submissions:read scope, NOT forms:manage — submission data access is
 * independently controllable (privacy default).
 */
class QuerySubmissionsTool implements ToolInterface
{
    /**
     * The filter-argument schema shared by the submission tools.
     *
     * @return array<string, mixed>
     */
    public static function filterProperties(): array
    {
        return [
            'form' => ['type' => 'string', 'description' => 'Filter by form handle.'],
            'formId' => ['type' => 'integer', 'description' => 'Filter by form id (takes precedence over "form").'],
            'status' => ['type' => 'string', 'description' => 'Filter by read status (e.g. "new", "read").'],
            'dateFrom' => ['type' => 'string', 'description' => 'Only submissions created on/after this date (ISO-8601).'],
            'dateTo' => ['type' => 'string', 'description' => 'Only submissions created on/before this date (ISO-8601).'],
            'fieldMatch' => [
                'type' => 'object',
                'description' => 'Match submissions whose stored field values equal the given handle => value pairs.',
                'additionalProperties' => true,
            ],
        ];
    }

    public function name(): string
    {
        return 'query_submissions';
    }

    public function description(): string
    {
        return 'Query Simple Form submissions with filters (form, status, date range, field-value '
            . 'match) and paging (offset/limit). Returns submission rows including their stored data.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => self::filterProperties() + [
                'offset' => ['type' => 'integer', 'minimum' => 0, 'description' => 'Paging offset. Defaults to 0.'],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Page size. Defaults to 50, max 200.'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return Scopes::SUBMISSIONS_READ;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(array $arguments): array
    {
        $built = SubmissionQueryBuilder::build($arguments);
        if (is_array($built)) {
            return $built; // error payload
        }
        /** @var SubmissionQuery $query */
        $query = $built;
        $query->with(['form']);

        $fieldMatch = is_array($arguments['fieldMatch'] ?? null) ? $arguments['fieldMatch'] : [];
        $offset = max(0, (int)($arguments['offset'] ?? 0));
        $limit = (int)($arguments['limit'] ?? 50);
        $limit = max(1, min(200, $limit));

        if ($fieldMatch === []) {
            // No post-fetch filter: page in the DB and report the true total.
            $total = (int)$query->count();
            $submissions = $query->offset($offset)->limit($limit)->all();
            $rows = array_map(
                static fn($s) => SubmissionQueryBuilder::present($s, true),
                $submissions
            );

            return [
                'total' => $total,
                'offset' => $offset,
                'limit' => $limit,
                'submissions' => $rows,
            ];
        }

        // fieldMatch is applied in PHP over the schemaless data blob, so fetch
        // the filtered set first, then page over the matches.
        $all = SubmissionQueryBuilder::applyFieldMatch($query->all(), $fieldMatch);
        $total = count($all);
        $page = array_slice($all, $offset, $limit);
        $rows = array_map(
            static fn($s) => SubmissionQueryBuilder::present($s, true),
            $page
        );

        return [
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'submissions' => $rows,
        ];
    }
}

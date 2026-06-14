<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\SubmissionQueryBuilder;

/**
 * MCP tool: export submissions as CSV or JSON.
 *
 * Uses the SAME filter set as query_submissions (via {@see SubmissionQueryBuilder})
 * so an export matches what a query returns. Gated behind the DISTINCT
 * submissions:export scope — a submissions:read token cannot export, and a
 * forms:manage token can do neither.
 */
class ExportSubmissionsTool implements ToolInterface
{
    public function name(): string
    {
        return 'export_submissions';
    }

    public function description(): string
    {
        return 'Export Simple Form submissions (matching the same filters as query_submissions) '
            . 'as CSV or JSON. Requires the submissions:export scope.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => QuerySubmissionsTool::filterProperties() + [
                'format' => [
                    'type' => 'string',
                    'enum' => ['csv', 'json'],
                    'description' => 'Output format. Defaults to "csv".',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return Scopes::SUBMISSIONS_EXPORT;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(array $arguments): array
    {
        $format = isset($arguments['format']) && is_string($arguments['format'])
            ? strtolower($arguments['format'])
            : 'csv';
        if (!in_array($format, ['csv', 'json'], true)) {
            return ['isError' => true, 'error' => 'format must be "csv" or "json".'];
        }

        $built = SubmissionQueryBuilder::build($arguments);
        if (is_array($built)) {
            return $built;
        }
        /** @var SubmissionQuery $query */
        $query = $built;
        $query->with(['form']);

        $fieldMatch = is_array($arguments['fieldMatch'] ?? null) ? $arguments['fieldMatch'] : [];
        $submissions = SubmissionQueryBuilder::applyFieldMatch($query->all(), $fieldMatch);

        $rows = array_map(
            static fn($s) => SubmissionQueryBuilder::present($s, true),
            $submissions
        );

        if ($format === 'json') {
            return [
                'format' => 'json',
                'count' => count($rows),
                'content' => (string)json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
        }

        return [
            'format' => 'csv',
            'count' => count($rows),
            'content' => $this->toCsv($rows),
        ];
    }

    /**
     * Render rows to CSV. Metadata columns come first, then one column per
     * distinct field handle seen across the result set (so every row aligns).
     * Array values (multi-value fields) are pipe-joined.
     *
     * @param list<array<string, mixed>> $rows
     */
    private function toCsv(array $rows): string
    {
        $meta = ['id', 'formId', 'formHandle', 'formName', 'siteId', 'status', 'userId', 'dateCreated'];

        $dataKeys = [];
        foreach ($rows as $row) {
            foreach (array_keys($row['data'] ?? []) as $key) {
                $dataKeys[(string)$key] = true;
            }
        }
        $dataKeys = array_keys($dataKeys);

        $header = array_merge($meta, $dataKeys);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }
        fputcsv($handle, $header);

        foreach ($rows as $row) {
            $line = [];
            foreach ($meta as $col) {
                $line[] = $this->scalar($row[$col] ?? null);
            }
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            foreach ($dataKeys as $key) {
                $line[] = $this->scalar($data[$key] ?? null);
            }
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = (string)stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value)) {
            return implode('|', array_map(fn($v) => $this->scalar($v), $value));
        }
        return (string)$value;
    }
}

<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\InsightCorpus;
use anvildev\simpleform\mcp\tools\support\SubmissionQueryBuilder;
use anvildev\simpleform\services\FieldTypeRegistry;

/**
 * AI-insight tool: support grouping/clustering of open-ended responses.
 *
 * Returns the free-text corpus PLUS server-side grouping signals the client can
 * use: when a closed-option field (select/radio/checkbox) is named (or
 * auto-detected) it groups submissions by that field's value and reports
 * frequency counts. The heavy clustering of free text is left to the client
 * model. Thin adapter over the shared query path; gated behind submissions:read.
 *
 * @phpstan-import-type McpError from ToolInterface
 */
class CategorizeSubmissionsTool implements ToolInterface
{
    private const MAX_ROWS = 500;

    public function name(): string
    {
        return 'categorize_submissions';
    }

    public function description(): string
    {
        return 'Group matching Simple Form submissions for the client to categorize: returns the '
            . 'free-text corpus plus server-side grouping signals (groups + frequency counts by a '
            . 'select/radio/checkbox field). The client model does any open-ended clustering.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => QuerySubmissionsTool::filterProperties() + [
                'groupBy' => [
                    'type' => 'string',
                    'description' => 'Field handle to group by. Defaults to the form\'s first '
                        . 'select/radio/checkbox field if any.',
                ],
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
     * @return array{count:int, groupBy:?string, textFields:list<string>, groups:list<array{value:string, count:int, submissionIds:list<int>}>, corpus:list<array{id:int, dateCreated:?string, fields:array<string, string>}>}|McpError
     */
    public function call(array $arguments): array
    {
        $query = SubmissionQueryBuilder::buildWithForm($arguments);
        if (is_array($query)) {
            return $query;
        }

        $fieldMatch = SubmissionQueryBuilder::fieldMatch($arguments);
        $submissions = SubmissionQueryBuilder::applyFieldMatch($query->all(), $fieldMatch);
        $submissions = array_slice($submissions, 0, self::MAX_ROWS);

        $form = InsightCorpus::resolveForm($arguments, $submissions);
        $fieldTypes = $form instanceof Form ? InsightCorpus::fieldTypes($form) : [];
        $textHandles = InsightCorpus::freeTextHandles($fieldTypes);

        $groupBy = $this->resolveGroupBy($arguments, $fieldTypes);

        // Server-side grouping signal: group submission ids by the value of the
        // chosen option field, with frequency counts.
        $groups = [];
        $corpus = [];
        foreach ($submissions as $submission) {
            $corpus[] = [
                'id' => (int)$submission->id,
                'dateCreated' => $submission->dateCreated?->format('c'),
                'fields' => InsightCorpus::textValues($submission, $textHandles),
            ];

            if ($groupBy === null) {
                continue;
            }
            foreach ($this->groupKeys($submission, $groupBy) as $key) {
                $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
                $groups[$key]['submissionIds'][] = (int)$submission->id;
            }
        }

        ksort($groups);

        return [
            'count' => count($submissions),
            'groupBy' => $groupBy,
            'textFields' => $textHandles,
            'groups' => $this->shapeGroups($groups),
            'corpus' => $corpus,
        ];
    }

    /**
     * Group keys for a submission's value of $groupBy. Multi-value (checkbox)
     * lands in every selected bucket; an empty value goes to "(none)".
     *
     * @return list<string>
     */
    private function groupKeys(Submission $submission, string $groupBy): array
    {
        $value = ($submission->data ?? [])[$groupBy] ?? null;
        if (is_array($value)) {
            $keys = array_values(array_filter(array_map('strval', $value), static fn($v) => $v !== ''));
            return $keys === [] ? ['(none)'] : $keys;
        }
        $str = trim((string)$value);
        return [$str === '' ? '(none)' : $str];
    }

    /**
     * @param array<string, array{count?:int,submissionIds?:list<int>}> $groups
     * @return list<array{value:string,count:int,submissionIds:list<int>}>
     */
    private function shapeGroups(array $groups): array
    {
        $out = [];
        foreach ($groups as $value => $info) {
            $out[] = [
                'value' => (string)$value,
                'count' => (int)($info['count'] ?? 0),
                'submissionIds' => array_values($info['submissionIds'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, string> $fieldTypes
     */
    private function resolveGroupBy(array $arguments, array $fieldTypes): ?string
    {
        if (isset($arguments['groupBy']) && is_string($arguments['groupBy']) && $arguments['groupBy'] !== '') {
            return $arguments['groupBy'];
        }
        // Auto-detect: first closed-option field in the schema.
        foreach ($fieldTypes as $handle => $type) {
            if (in_array($type, FieldTypeRegistry::OPTION_TYPES, true)) {
                return $handle;
            }
        }

        return null;
    }
}

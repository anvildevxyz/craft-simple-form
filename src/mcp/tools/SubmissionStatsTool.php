<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\SubmissionQueryBuilder;

/**
 * MCP tool: aggregate submission counts — total, per-status, per-form, and over
 * time (per day). Uses the same filter set as query_submissions.
 *
 * Gated behind submissions:read. Reports counts only, never raw submission data.
 */
class SubmissionStatsTool implements ToolInterface
{
    public function name(): string
    {
        return 'submission_stats';
    }

    public function description(): string
    {
        return 'Aggregate Simple Form submission counts: total, per-status, per-form, and over '
            . 'time (per day). Honours the same filters as query_submissions.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => QuerySubmissionsTool::filterProperties(),
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
        $query = SubmissionQueryBuilder::buildWithForm($arguments);
        if (is_array($query)) {
            return $query;
        }

        $fieldMatch = SubmissionQueryBuilder::fieldMatch($arguments);
        $submissions = SubmissionQueryBuilder::applyFieldMatch($query->all(), $fieldMatch);

        $perStatus = [];
        $perForm = [];
        $perDay = [];

        foreach ($submissions as $submission) {
            $status = $submission->readStatus;
            $perStatus[$status] = ($perStatus[$status] ?? 0) + 1;

            $form = $submission->getForm();
            $handle = $form?->handle ?? ('formId:' . ($submission->formId ?? '0'));
            $perForm[$handle] = ($perForm[$handle] ?? 0) + 1;

            $day = $submission->dateCreated?->format('Y-m-d') ?? 'unknown';
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }

        ksort($perDay);

        return [
            'total' => count($submissions),
            'perStatus' => $perStatus,
            'perForm' => $perForm,
            'perDay' => $perDay,
        ];
    }
}

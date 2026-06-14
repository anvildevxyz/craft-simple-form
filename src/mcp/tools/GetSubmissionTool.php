<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\SubmissionQueryBuilder;

/**
 * MCP tool: fetch a single submission's full detail by id.
 *
 * Gated behind submissions:read (distinct from forms:manage).
 */
class GetSubmissionTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_submission';
    }

    public function description(): string
    {
        return 'Get the full detail of a single Simple Form submission by id, including its '
            . 'stored field values.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The submission id. Required.'],
            ],
            'required' => ['id'],
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
        if (!isset($arguments['id'])) {
            return ['isError' => true, 'error' => 'Provide a submission "id".'];
        }

        $submission = Submission::find()
            ->siteId('*')
            ->id((int)$arguments['id'])
            ->with(['form'])
            ->one();

        if (!$submission instanceof Submission) {
            return ['isError' => true, 'error' => 'Submission not found.'];
        }

        return ['submission' => SubmissionQueryBuilder::present($submission, true)];
    }
}

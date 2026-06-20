<?php

namespace fabianhaef\simpleform\mcp\tools\support;

use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;

/**
 * Builds a {@see SubmissionQuery} from the filter arguments shared by the
 * submission MCP tools (query/export/stats), so every tool interprets form,
 * status, date-range and field-value filters identically.
 *
 * Routes through the existing {@see Submission} element query — submissions are
 * read here, never written.
 *
 * @phpstan-import-type McpError from \fabianhaef\simpleform\mcp\tools\ToolInterface
 */
final class SubmissionQueryBuilder
{
    /**
     * @param array<string, mixed> $args
     * @return SubmissionQuery|McpError the query, or an
     *   error payload when a referenced form can't be resolved.
     */
    public static function build(array $args): SubmissionQuery|array
    {
        // Submissions are localized; search across all sites so results don't
        // depend on the request's resolved site (see the multi-site memory note).
        $query = Submission::find()->siteId('*');

        if (isset($args['formId'])) {
            $query->formId((int)$args['formId']);
        } elseif (isset($args['form']) && is_string($args['form']) && $args['form'] !== '') {
            $form = Form::find()->siteId('*')->handle($args['form'])->status(null)->one();
            if (!$form instanceof Form) {
                return ['isError' => true, 'error' => 'Form not found: ' . $args['form']];
            }
            $query->formId((int)$form->id);
        }

        if (isset($args['status']) && is_string($args['status']) && $args['status'] !== '') {
            $query->readStatus($args['status']);
        }

        // Date range over the element's dateCreated (the submission timestamp).
        $dateFrom = isset($args['dateFrom']) ? (string)$args['dateFrom'] : null;
        $dateTo = isset($args['dateTo']) ? (string)$args['dateTo'] : null;
        if ($dateFrom !== null && $dateFrom !== '' && $dateTo !== null && $dateTo !== '') {
            $query->dateCreated(['and', '>= ' . $dateFrom, '<= ' . $dateTo]);
        } elseif ($dateFrom !== null && $dateFrom !== '') {
            $query->dateCreated('>= ' . $dateFrom);
        } elseif ($dateTo !== null && $dateTo !== '') {
            $query->dateCreated('<= ' . $dateTo);
        }

        return $query;
    }

    /**
     * Apply an in-PHP field-value filter to a fetched submission set. The
     * submission `data` blob is schemaless JSON, so this can't be pushed into
     * SQL portably; filtering after fetch keeps it DB-agnostic.
     *
     * @param list<Submission> $submissions
     * @param array<string, mixed> $fieldMatch handle => expected value (loose string match)
     * @return list<Submission>
     */
    public static function applyFieldMatch(array $submissions, array $fieldMatch): array
    {
        if ($fieldMatch === []) {
            return $submissions;
        }

        return array_values(array_filter($submissions, static function(Submission $s) use ($fieldMatch): bool {
            $data = $s->data ?? [];
            foreach ($fieldMatch as $handle => $expected) {
                $actual = $data[$handle] ?? null;
                if (is_array($actual)) {
                    // Multi-value field: match if the expected value is present.
                    if (!in_array((string)$expected, array_map('strval', $actual), true)) {
                        return false;
                    }
                } elseif ((string)$actual !== (string)$expected) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Shape a submission for tool output. `includeData` controls whether the
     * full field-value blob is included (list views omit it for brevity).
     *
     * @return array<string, mixed>
     */
    public static function present(Submission $submission, bool $includeData = true): array
    {
        $form = $submission->getForm();

        $out = [
            'id' => (int)$submission->id,
            'formId' => $submission->formId !== null ? (int)$submission->formId : null,
            'formHandle' => $form?->handle,
            'formName' => $form?->name,
            'siteId' => (int)$submission->siteId,
            'status' => $submission->readStatus,
            'userId' => $submission->userId !== null ? (int)$submission->userId : null,
            'dateCreated' => $submission->dateCreated?->format('c'),
        ];

        if ($includeData) {
            $out['data'] = $submission->data ?? [];
        }

        return $out;
    }
}

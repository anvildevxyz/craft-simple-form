<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\InsightCorpus;
use anvildev\simpleform\mcp\tools\support\SubmissionQueryBuilder;

/**
 * AI-insight tool: shape the free-text corpus of a filtered submission set for
 * the CLIENT model to summarize.
 *
 * This tool does NOT call an LLM — it returns the relevant text (one entry per
 * submission, restricted to the form's free-text fields when the schema is
 * known) so the client can produce the summary. Thin adapter over the shared
 * {@see SubmissionQueryBuilder}; gated behind submissions:read.
 *
 * @phpstan-import-type McpError from ToolInterface
 */
class SummarizeSubmissionsTool implements ToolInterface
{
    /** Cap the corpus so a huge form can't return an unbounded blob. */
    private const MAX_ROWS = 500;

    public function name(): string
    {
        return 'summarize_submissions';
    }

    public function description(): string
    {
        return 'Return the free-text corpus of matching Simple Form submissions (per-submission '
            . 'text from the form\'s open-ended fields) for the client model to summarize. The '
            . 'tool shapes and returns text only; it does not itself call an LLM.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => QuerySubmissionsTool::filterProperties() + [
                'fields' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Restrict the corpus to these field handles. Defaults to the '
                        . 'form\'s free-text fields (text/textarea/email).',
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
     * @return array{count:int, totalMatched:int, fields:list<string>, wordCount:int, corpus:list<array{id:int, dateCreated:?string, fields:array<string, string>, text:string}>}|McpError
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

        // Resolve the free-text field set from the form schema when possible; an
        // explicit "fields" argument wins.
        $handles = $this->resolveHandles($arguments, $submissions);

        $entries = [];
        $wordCount = 0;
        foreach ($submissions as $submission) {
            $values = InsightCorpus::textValues($submission, $handles);
            if ($values === []) {
                continue;
            }
            $text = implode("\n", $values);
            $wordCount += str_word_count($text);
            $entries[] = [
                'id' => (int)$submission->id,
                'dateCreated' => $submission->dateCreated?->format('c'),
                'fields' => $values,
                'text' => $text,
            ];
        }

        return [
            'count' => count($entries),
            'totalMatched' => count($submissions),
            'fields' => $handles,
            'wordCount' => $wordCount,
            'corpus' => $entries,
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<\anvildev\simpleform\elements\Submission> $submissions
     * @return list<string>
     */
    private function resolveHandles(array $arguments, array $submissions): array
    {
        if (is_array($arguments['fields'] ?? null) && $arguments['fields'] !== []) {
            return array_values(array_filter(array_map('strval', $arguments['fields']), static fn($h) => $h !== ''));
        }

        $form = InsightCorpus::resolveForm($arguments, $submissions);
        if ($form instanceof Form) {
            return InsightCorpus::freeTextHandles(InsightCorpus::fieldTypes($form));
        }

        // No resolvable schema: empty list signals "treat every string as text".
        return [];
    }
}

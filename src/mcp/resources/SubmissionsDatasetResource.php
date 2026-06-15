<?php

namespace fabianhaef\simpleform\mcp\resources;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\SubmissionQueryBuilder;

/**
 * MCP resource provider for {@code submissions://{handle}} — a form's submission
 * dataset.
 *
 * Gated behind submissions:read (the privacy default). A token without that
 * scope must NOT see these resources in {@code resources/list} NOR be able to
 * {@code resources/read} them; the dispatcher enforces both. Contents reuse the
 * same {@see SubmissionQueryBuilder} presentation as the submission tools so the
 * dataset matches query_submissions.
 */
final class SubmissionsDatasetResource implements ResourceProviderInterface
{
    private const SCHEME = 'submissions';
    private const MIME = 'application/json';

    /** Cap a single resource read so a huge form can't return an unbounded blob. */
    private const MAX_ROWS = 500;

    public function requiredScope(): string
    {
        return Scopes::SUBMISSIONS_READ;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        $resources = [];
        $forms = Form::find()->siteId('*')->status(null)->all();
        foreach ($forms as $form) {
            if (!$form instanceof Form || $form->handle === null) {
                continue;
            }
            $resources[] = [
                'uri' => self::SCHEME . '://' . $form->handle,
                'name' => ($form->name ?? $form->handle) . ' submissions',
                'title' => ($form->title ?? $form->name ?? $form->handle) . ' submissions',
                'description' => 'Submission dataset (up to ' . self::MAX_ROWS . ' rows) for the "'
                    . ($form->name ?? $form->handle) . '" form.',
                'mimeType' => self::MIME,
            ];
        }

        return $resources;
    }

    public function handles(string $uri): bool
    {
        return str_starts_with($uri, self::SCHEME . '://');
    }

    /**
     * @return array{contents:list<array<string, mixed>>}|array{isError:true,error:string}
     */
    public function read(string $uri): array
    {
        $handle = substr($uri, strlen(self::SCHEME . '://'));
        if ($handle === '') {
            return ['isError' => true, 'error' => 'Missing form handle in URI: ' . $uri];
        }

        $form = Form::find()->siteId('*')->status(null)->handle($handle)->one();
        if (!$form instanceof Form) {
            return ['isError' => true, 'error' => 'Form not found: ' . $handle];
        }

        // Route through the shared submission query so the dataset matches the
        // submission tools exactly (form filter, multi-site, presentation).
        $built = SubmissionQueryBuilder::build(['formId' => (int)$form->id]);
        if (is_array($built)) {
            return $built;
        }

        $built->with(['form']);
        $total = (int)$built->count();
        $submissions = $built->limit(self::MAX_ROWS)->all();
        $rows = array_map(
            static fn($s) => SubmissionQueryBuilder::present($s, true),
            $submissions,
        );

        $payload = [
            'form' => $form->handle,
            'total' => $total,
            'returned' => count($rows),
            'truncated' => $total > count($rows),
            'submissions' => $rows,
        ];

        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => self::MIME,
                'text' => (string)json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
        ];
    }
}

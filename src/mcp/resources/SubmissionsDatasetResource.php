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
 * dataset matches query_submissions. The list/read/handles plumbing lives in
 * {@see AbstractFormResource}; this provider only declares its scheme, scope,
 * MIME, descriptor and payload.
 */
final class SubmissionsDatasetResource extends AbstractFormResource
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    private const SCHEME = 'submissions';
    private const MIME = 'application/json';

    /** Cap a single resource read so a huge form can't return an unbounded blob. */
    private const MAX_ROWS = 500;

    // =========================================================================
    // Public Methods
    // =========================================================================

    public function requiredScope(): string
    {
        return Scopes::SUBMISSIONS_READ;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    protected function scheme(): string
    {
        return self::SCHEME;
    }

    protected function mimeType(): string
    {
        return self::MIME;
    }

    /**
     * @inheritdoc
     */
    protected function describe(Form $form): array
    {
        return [
            'uri' => self::SCHEME . '://' . $form->handle,
            'name' => ($form->name ?? $form->handle) . ' submissions',
            'title' => ($form->title ?? $form->name ?? $form->handle) . ' submissions',
            'description' => 'Submission dataset (up to ' . self::MAX_ROWS . ' rows) for the "'
                . ($form->name ?? $form->handle) . '" form.',
            'mimeType' => self::MIME,
        ];
    }

    /**
     * @inheritdoc
     */
    protected function payload(Form $form): array
    {
        // Route through the shared submission query so the dataset matches the
        // submission tools exactly (form filter, multi-site, presentation).
        $query = SubmissionQueryBuilder::buildWithForm(['formId' => (int)$form->id]);
        if (is_array($query)) {
            // A referenced form can't fail to resolve here (we hold the element),
            // but keep the shape stable if the builder ever errors.
            return $query;
        }

        $total = (int)$query->count();
        $submissions = $query->limit(self::MAX_ROWS)->all();
        $rows = array_map(
            static fn($s) => SubmissionQueryBuilder::present($s, true),
            $submissions,
        );

        return [
            'form' => $form->handle,
            'total' => $total,
            'returned' => count($rows),
            'truncated' => $total > count($rows),
            'submissions' => $rows,
        ];
    }
}

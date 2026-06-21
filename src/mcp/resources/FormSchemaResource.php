<?php

namespace fabianhaef\simpleform\mcp\resources;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\FormPresenter;

/**
 * MCP resource provider for {@code form://{handle}} — a form's live schema
 * (metadata + resolved field set: types, options, validation).
 *
 * Follows the forms:manage scope, matching how the form-management tools are
 * gated. Contents reuse {@see FormPresenter} so the resource and {@code get_form}
 * never disagree about the schema. The list/read/handles plumbing lives in
 * {@see AbstractFormResource}; this provider only declares its scheme, scope,
 * MIME, descriptor and payload.
 */
final class FormSchemaResource extends AbstractFormResource
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    private const SCHEME = 'form';
    private const MIME = 'application/json';

    // =========================================================================
    // Public Methods
    // =========================================================================

    public function requiredScope(): string
    {
        return Scopes::FORMS_MANAGE;
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
            'name' => $form->name ?? $form->handle,
            'title' => $form->title ?? $form->name ?? $form->handle,
            'description' => 'Schema (fields, types, options, validation) for the "'
                . ($form->name ?? $form->handle) . '" form.',
            'mimeType' => self::MIME,
        ];
    }

    /**
     * @inheritdoc
     */
    protected function payload(Form $form): array
    {
        // Reuse the tool-layer presenter so the resource schema matches get_form.
        return FormPresenter::form($form);
    }
}

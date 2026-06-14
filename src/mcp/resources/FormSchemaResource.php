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
 * never disagree about the schema.
 */
final class FormSchemaResource implements ResourceProviderInterface
{
    private const SCHEME = 'form';
    private const MIME = 'application/json';

    public function scheme(): string
    {
        return self::SCHEME;
    }

    public function requiredScope(): string
    {
        return Scopes::FORMS_MANAGE;
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
                'name' => $form->name ?? $form->handle,
                'title' => $form->title ?? $form->name ?? $form->handle,
                'description' => 'Schema (fields, types, options, validation) for the "'
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

        // Reuse the tool-layer presenter so the resource schema matches get_form.
        $schema = FormPresenter::form($form);

        return [
            'contents' => [[
                'uri' => $uri,
                'mimeType' => self::MIME,
                'text' => (string)json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ]],
        ];
    }
}

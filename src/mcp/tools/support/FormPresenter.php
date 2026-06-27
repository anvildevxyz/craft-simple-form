<?php

namespace anvildev\simpleform\mcp\tools\support;

use anvildev\simpleform\elements\Form;

/**
 * Shapes {@see Form} elements and their resolved field sets into the structured
 * JSON returned by the form-management MCP tools, so every tool reports a form
 * the same way.
 *
 * @phpstan-import-type McpError from \anvildev\simpleform\mcp\tools\ToolInterface
 */
final class FormPresenter
{
    /**
     * Resolve a single form from the shared `id`-or-`handle` tool arguments,
     * across all sites (`siteId('*')`), so the form-management tools resolve a
     * form identically. Returns the {@see Form} on success, or the in-band
     * {@see McpError} payload when neither argument is given or no form matches.
     *
     * @param array<string, mixed> $arguments
     * @return Form|McpError
     */
    public static function resolveByIdOrHandle(array $arguments): Form|array
    {
        $query = Form::find()->siteId('*')->status(null)->unique();

        if (isset($arguments['id'])) {
            $query->id((int)$arguments['id']);
        } elseif (isset($arguments['handle']) && is_string($arguments['handle'])) {
            $query->handle($arguments['handle']);
        } else {
            return ['isError' => true, 'error' => 'Provide either "id" or "handle".'];
        }

        $form = $query->one();
        if (!$form instanceof Form) {
            return ['isError' => true, 'error' => 'Form not found.'];
        }

        return $form;
    }

    /**
     * The shared `id`/`handle` inputSchema property pair, so every form tool
     * advertises the same id-or-handle selector.
     *
     * @return array<string, array<string, string>>
     */
    public static function idOrHandleProperties(): array
    {
        return [
            'id' => ['type' => 'integer', 'description' => 'The form id. Provide id OR handle.'],
            'handle' => ['type' => 'string', 'description' => 'The form handle. Provide id OR handle.'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function form(Form $form): array
    {
        return [
            'id' => (int)$form->id,
            'siteId' => (int)$form->siteId,
            'handle' => $form->handle,
            'name' => $form->name,
            'title' => $form->title,
            'description' => $form->description,
            'emailTo' => $form->emailTo,
            'emailSubject' => $form->emailSubject,
            'emailReplyTo' => $form->emailReplyTo,
            'emailBody' => $form->emailBody,
            'propagationMethod' => $form->propagationMethod->value,
            'fields' => self::fields($form),
        ];
    }

    /**
     * The form's resolved field set, in a stable tool shape.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(Form $form): array
    {
        $fields = [];
        foreach ($form->getFields() as $row) {
            $fields[] = [
                'id' => (int)$row['id'],
                'type' => (string)$row['type'],
                'handle' => (string)$row['name'],
                'label' => (string)$row['label'],
                'required' => (bool)$row['required'],
                'helpText' => (string)($row['helpText'] ?? ''),
                'sortOrder' => (int)$row['sortOrder'],
                'config' => $row['config'],
            ];
        }

        return $fields;
    }
}

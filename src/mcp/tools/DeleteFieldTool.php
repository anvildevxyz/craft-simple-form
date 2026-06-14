<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\FieldOps;

/**
 * MCP tool: delete a single field from a form.
 *
 * Destructive, so it REFUSES unless the caller passes `confirm: true`. Mirrors
 * the CP's FieldsController::actionDelete via {@see FieldOps} (per-site rows
 * cascade via FK; the form's structure cache is invalidated).
 */
class DeleteFieldTool implements ToolInterface
{
    public function name(): string
    {
        return 'delete_field';
    }

    public function description(): string
    {
        return 'Delete a single field from a Simple Form form. DESTRUCTIVE: requires '
            . '"confirm": true; the call is refused without it.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fieldId' => ['type' => 'integer', 'description' => 'The field id to delete. Required.'],
                'confirm' => [
                    'type' => 'boolean',
                    'description' => 'Must be true to actually delete. The call is refused without it.',
                ],
            ],
            'required' => ['fieldId', 'confirm'],
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return Scopes::FORMS_MANAGE;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function call(array $arguments): array
    {
        if (($arguments['confirm'] ?? false) !== true) {
            return [
                'isError' => true,
                'error' => 'Refused: deleting a field is destructive. Pass "confirm": true to proceed.',
            ];
        }

        $fieldId = isset($arguments['fieldId']) ? (int)$arguments['fieldId'] : 0;
        $field = FieldOps::findField($fieldId);
        if ($field === null) {
            return ['isError' => true, 'error' => 'Field not found.'];
        }

        FieldOps::delete($fieldId, (int)$field['formId']);

        return ['deleted' => true, 'fieldId' => $fieldId, 'formId' => (int)$field['formId']];
    }
}

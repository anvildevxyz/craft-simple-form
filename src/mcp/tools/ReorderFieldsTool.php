<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\FieldOps;
use anvildev\simpleform\mcp\tools\support\FormPresenter;
use craft\db\Query;

/**
 * MCP tool: reorder a form's fields.
 *
 * Mirrors the CP's FieldsController::actionReorder via {@see FieldOps}. The
 * supplied id list defines the new order; it must contain exactly the form's
 * current field ids so a partial/foreign list can't silently corrupt order.
 */
class ReorderFieldsTool implements ToolInterface
{
    public function name(): string
    {
        return 'reorder_fields';
    }

    public function description(): string
    {
        return 'Reorder a Simple Form form\'s fields. Provide the form id and the complete '
            . 'list of its field ids in the desired order.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'formId' => ['type' => 'integer', 'description' => 'The form whose fields to reorder. Required.'],
                'fieldIds' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'All of the form\'s field ids, in the desired order. Required.',
                ],
            ],
            'required' => ['formId', 'fieldIds'],
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
        $formId = isset($arguments['formId']) ? (int)$arguments['formId'] : 0;
        $fieldIds = is_array($arguments['fieldIds'] ?? null)
            ? array_map('intval', $arguments['fieldIds'])
            : [];

        if ($fieldIds === []) {
            return ['isError' => true, 'error' => 'Provide a non-empty "fieldIds" array.'];
        }

        $form = Form::find()->id($formId)->siteId('*')->status(null)->one();
        if (!$form instanceof Form) {
            return ['isError' => true, 'error' => 'Form not found.'];
        }

        // The supplied ids must be exactly the form's current field ids — no
        // foreign ids, none missing — so a reorder can't move another form's
        // fields or drop one.
        $actual = (new Query())
            ->select(['id'])
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId])
            ->column();
        $actual = array_map('intval', $actual);

        sort($actual);
        $sortedInput = $fieldIds;
        sort($sortedInput);
        if ($sortedInput !== $actual) {
            return [
                'isError' => true,
                'error' => 'fieldIds must contain exactly the form\'s current field ids.',
            ];
        }

        FieldOps::reorder($fieldIds);

        $fresh = Form::find()->id($formId)->siteId('*')->status(null)->one();

        return [
            'reordered' => true,
            'form' => $fresh instanceof Form ? FormPresenter::form($fresh) : null,
        ];
    }
}

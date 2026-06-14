<?php

namespace fabianhaef\simpleform\mcp\tools;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\FieldOps;
use fabianhaef\simpleform\mcp\tools\support\FormPresenter;

/**
 * MCP tool: update an existing field.
 *
 * Mirrors the CP's FieldsController::actionEdit via {@see FieldOps}. The field
 * type is immutable (matching the CP, which edits the existing row's type). Only
 * supplied attributes change; unspecified ones keep their current value.
 */
class UpdateFieldTool implements ToolInterface
{
    public function name(): string
    {
        return 'update_field';
    }

    public function description(): string
    {
        return 'Update a field on a Simple Form form (label, handle, required, help text, config). '
            . 'Same validation as the Control Panel; errors are returned in-band.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'fieldId' => ['type' => 'integer', 'description' => 'The field id to update. Required.'],
                'handle' => ['type' => 'string', 'description' => 'New handle (unique within the form).'],
                'label' => ['type' => 'string', 'description' => 'New label (per-site).'],
                'required' => ['type' => 'boolean', 'description' => 'Whether the field is required.'],
                'helpText' => ['type' => 'string', 'description' => 'New help text (per-site).'],
                'config' => [
                    'type' => 'object',
                    'description' => 'New field type config. select/checkbox/radio require an "options" array.',
                    'additionalProperties' => true,
                ],
                'siteId' => ['type' => 'integer', 'description' => 'Site whose label/help text to update. Defaults to the primary site.'],
            ],
            'required' => ['fieldId'],
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
        $fieldId = isset($arguments['fieldId']) ? (int)$arguments['fieldId'] : 0;
        $field = FieldOps::findField($fieldId);
        if ($field === null) {
            return ['isError' => true, 'error' => 'Field not found.'];
        }

        $formId = (int)$field['formId'];
        $type = (string)$field['type'];

        // Default unspecified attributes to the field's current values so a
        // partial update doesn't blank columns.
        $currentConfig = $field['config'] ? (json_decode((string)$field['config'], true) ?: []) : [];
        $handle = array_key_exists('handle', $arguments) ? (string)$arguments['handle'] : (string)$field['name'];
        $required = array_key_exists('required', $arguments) ? (bool)$arguments['required'] : (bool)$field['required'];
        $config = array_key_exists('config', $arguments) && is_array($arguments['config'])
            ? $arguments['config']
            : (is_array($currentConfig) ? $currentConfig : []);

        $siteId = isset($arguments['siteId'])
            ? (int)$arguments['siteId']
            : (int)Craft::$app->getSites()->getPrimarySite()->id;

        // Label is per-site; default to the existing label on the target site.
        if (array_key_exists('label', $arguments)) {
            $label = (string)$arguments['label'];
        } else {
            $label = $this->currentLabel($fieldId, $siteId) ?? (string)$field['name'];
        }

        $helpText = array_key_exists('helpText', $arguments)
            ? (string)$arguments['helpText']
            : (string)($this->currentHelpText($fieldId, $siteId) ?? '');

        $errors = FieldOps::validate($type, $label, $handle, $config, $formId, $fieldId);
        if ($errors !== []) {
            return ['isError' => true, 'errors' => $errors];
        }

        FieldOps::update($fieldId, $formId, $siteId, $type, $handle, $label, $required, $helpText, $config);

        $fresh = Form::find()->id($formId)->siteId($siteId)->status(null)->one()
            ?? Form::find()->id($formId)->siteId('*')->status(null)->one();

        return [
            'fieldId' => $fieldId,
            'form' => $fresh instanceof Form ? FormPresenter::form($fresh) : null,
        ];
    }

    private function currentLabel(int $fieldId, int $siteId): ?string
    {
        $label = (new \craft\db\Query())
            ->select(['label'])
            ->from('{{%simpleform_fields_sites}}')
            ->where(['fieldId' => $fieldId, 'siteId' => $siteId])
            ->scalar();
        return is_string($label) ? $label : null;
    }

    private function currentHelpText(int $fieldId, int $siteId): ?string
    {
        $help = (new \craft\db\Query())
            ->select(['helpText'])
            ->from('{{%simpleform_fields_sites}}')
            ->where(['fieldId' => $fieldId, 'siteId' => $siteId])
            ->scalar();
        return is_string($help) ? $help : null;
    }
}

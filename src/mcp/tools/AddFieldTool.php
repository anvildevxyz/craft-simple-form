<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\FieldOps;
use anvildev\simpleform\mcp\tools\support\FormPresenter;

/**
 * MCP tool: add a field to a form.
 *
 * Mirrors the CP's FieldsController::actionAdd via {@see FieldOps}: same
 * validation, same structural + per-site rows, same cache invalidation. The
 * type enum is generated from the field-type registry so it stays in sync.
 */
class AddFieldTool implements ToolInterface
{
    public function name(): string
    {
        return 'add_field';
    }

    public function description(): string
    {
        return 'Add a field to a Simple Form form. Same validation and multi-site behaviour '
            . 'as the Control Panel; validation errors are returned in-band.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'formId' => ['type' => 'integer', 'description' => 'The form id to add the field to. Required.'],
                'type' => FieldOps::typeSchema(),
                'handle' => ['type' => 'string', 'description' => 'Field handle (unique within the form). Required.'],
                'label' => ['type' => 'string', 'description' => 'Field label (per-site). Required.'],
                'required' => ['type' => 'boolean', 'description' => 'Whether the field is required. Defaults to false.'],
                'helpText' => ['type' => 'string', 'description' => 'Help text shown under the field (per-site).'],
                'config' => [
                    'type' => 'object',
                    'description' => 'Field type config. select/checkbox/radio require an "options" array of {value,label}. '
                        . 'Optional "conditional" object adds show/hide + conditional-required logic: '
                        . '{enabled:true, action:"show"|"hide", match:"all"|"any", rules:[{field:<handle>, operator:"eq"|"neq"|"empty"|"notEmpty"|"contains"|"gt"|"lt", value:<string>}], '
                        . 'required:{enabled:true, match:"all"|"any", rules:[...]}}. Rules reference other fields by handle; self-reference and cycles are rejected, dangling refs pruned.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['formId', 'type', 'handle', 'label'],
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
        $type = isset($arguments['type']) ? (string)$arguments['type'] : '';
        $handle = isset($arguments['handle']) ? (string)$arguments['handle'] : '';
        $label = isset($arguments['label']) ? (string)$arguments['label'] : '';
        $required = (bool)($arguments['required'] ?? false);
        $helpText = isset($arguments['helpText']) ? (string)$arguments['helpText'] : '';
        $config = is_array($arguments['config'] ?? null) ? $arguments['config'] : [];

        $form = Form::find()->id($formId)->siteId('*')->status(null)->one();
        if (!$form instanceof Form) {
            return ['isError' => true, 'error' => 'Form not found.'];
        }

        // Edition gate (authoritative): adding a new field is always an escalation,
        // so Solo may not add a Pro field type here — the same rule the CP save
        // enforces, which this non-CP authoring path would otherwise bypass.
        if (!Editions::fieldTypeAllowed($type)) {
            return [
                'isError' => true,
                'errors' => ['type' => [sprintf('The "%s" field type requires the Pro edition.', $type)]],
            ];
        }

        // Same gate for Pro capabilities the field's own config may introduce
        // (conditional logic, a 2nd-page placement), diffed against the form's
        // existing fields so an already-used capability isn't re-blocked.
        $blockedCaps = Editions::blockedNewFormCapabilities(
            [['type' => $type, 'config' => $config]],
            false,
            $form->getFields(),
            false,
        );
        if ($blockedCaps !== []) {
            return [
                'isError' => true,
                'errors' => ['config' => [sprintf('This field uses features that require the Pro edition: %s.', implode(', ', $blockedCaps))]],
            ];
        }

        $errors = FieldOps::validate($type, $label, $handle, $config, $formId, null);
        if ($errors !== []) {
            return ['isError' => true, 'errors' => $errors];
        }

        $fieldId = FieldOps::add($formId, $type, $handle, $label, $required, $helpText, $config);

        $fresh = Form::find()->id($formId)->siteId('*')->status(null)->one();

        return [
            'fieldId' => $fieldId,
            'form' => $fresh instanceof Form ? FormPresenter::form($fresh) : null,
        ];
    }
}

<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\ConfirmGate;
use anvildev\simpleform\mcp\tools\support\FormPresenter;
use Craft;

/**
 * MCP tool: delete a form (element-wide, all sites).
 *
 * Destructive, so it REFUSES unless the caller passes an explicit
 * `confirm: true`. Routes through the CP's element deletion path
 * ({@see \Craft::$app}->getElements()->deleteElement()), so Form::afterDelete
 * cache invalidation and the FK cascade of fields/per-site rows all apply.
 */
class DeleteFormTool implements ToolInterface
{
    public function name(): string
    {
        return 'delete_form';
    }

    public function description(): string
    {
        return 'Delete a Simple Form form and all of its fields (across every site). '
            . 'DESTRUCTIVE: requires "confirm": true; the call is refused without it.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => FormPresenter::idOrHandleProperties() + ConfirmGate::schemaProperty(),
            'required' => ['confirm'],
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
        if (($refusal = ConfirmGate::refusalUnlessConfirmed($arguments, 'a form')) !== null) {
            return $refusal;
        }

        $form = FormPresenter::resolveByIdOrHandle($arguments);
        if (is_array($form)) {
            return $form;
        }

        $formId = (int)$form->id;
        if (!Craft::$app->getElements()->deleteElement($form)) {
            return ['isError' => true, 'errors' => $form->getErrors()];
        }

        return ['deleted' => true, 'id' => $formId];
    }
}

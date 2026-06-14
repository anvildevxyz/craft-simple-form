<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\FormPresenter;

/**
 * MCP tool: fetch a single form's full definition (metadata + resolved field
 * set) by id or handle. Routes through the {@see Form} element layer; never
 * exposes submission data.
 */
class GetFormTool implements ToolInterface
{
    public function name(): string
    {
        return 'get_form';
    }

    public function description(): string
    {
        return 'Get the full definition of a single Simple Form form (metadata and its fields) '
            . 'by id or handle. Does not expose any submission data.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The form id. Provide id OR handle.'],
                'handle' => ['type' => 'string', 'description' => 'The form handle. Provide id OR handle.'],
            ],
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

        return ['form' => FormPresenter::form($form)];
    }
}

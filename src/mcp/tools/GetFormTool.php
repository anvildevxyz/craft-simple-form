<?php

namespace anvildev\simpleform\mcp\tools;

use anvildev\simpleform\mcp\Scopes;
use anvildev\simpleform\mcp\tools\support\FormPresenter;

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
            'properties' => FormPresenter::idOrHandleProperties(),
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
        $form = FormPresenter::resolveByIdOrHandle($arguments);
        if (is_array($form)) {
            return $form;
        }

        return ['form' => FormPresenter::form($form)];
    }
}

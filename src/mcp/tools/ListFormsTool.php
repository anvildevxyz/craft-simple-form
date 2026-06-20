<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\Scopes;

/**
 * MCP tool: list the plugin's forms (id, handle, name, field count).
 *
 * Routes through the existing {@see Form} element layer (the same query the CP
 * and GraphQL use) rather than introducing new business logic, and exposes only
 * form *structure* metadata — never any submission data.
 */
class ListFormsTool implements ToolInterface
{
    public function name(): string
    {
        return 'list_forms';
    }

    public function description(): string
    {
        return 'List the Simple Form forms defined in this Craft installation, '
            . 'returning each form\'s id, handle, name, and number of fields. '
            . 'Does not expose any submission data.';
    }

    /**
     * @return array<string, mixed>
     */
    public function inputSchema(): array
    {
        // No arguments. A closed object (additionalProperties:false) so a client
        // cannot smuggle unexpected input.
        return [
            'type' => 'object',
            'properties' => (object)[],
            'additionalProperties' => false,
        ];
    }

    public function requiredScope(): string
    {
        return Scopes::FORMS_MANAGE;
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{forms: list<array{id:int, handle:?string, name:?string, fieldCount:int}>}
     */
    public function call(array $arguments): array
    {
        // Forms are localized; query across all sites so the result is complete
        // regardless of which site context the request resolves to. Eager-load
        // field sets to avoid an N+1 when counting fields.
        $forms = Form::find()
            ->siteId('*')
            ->unique()
            ->all();

        if ($forms !== []) {
            Form::eagerLoadFields($forms);
        }

        $result = [];
        foreach ($forms as $form) {
            $result[] = [
                'id' => (int)$form->id,
                'handle' => $form->handle,
                'name' => $form->name,
                'fieldCount' => count($form->getFields()),
            ];
        }

        return ['forms' => $result];
    }
}

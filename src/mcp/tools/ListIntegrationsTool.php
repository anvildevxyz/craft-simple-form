<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\Plugin;

/**
 * MCP tool: list the outbound integrations configured on a form, with recent
 * dispatch health. Read-only and intentionally NEVER exposes integration
 * settings or secrets — only name, type, enabled, and dispatch counts.
 */
class ListIntegrationsTool implements ToolInterface
{
    public function name(): string
    {
        return 'list_integrations';
    }

    public function description(): string
    {
        return 'List the outbound integrations configured on a Simple Form form, with recent '
            . 'dispatch health (attempt counts and last status). Does not expose integration '
            . 'settings or secrets.';
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
        // Integrations are form configuration, gated by the same scope as form management.
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
            $query->id((int) $arguments['id']);
        } elseif (isset($arguments['handle']) && is_string($arguments['handle'])) {
            $query->handle($arguments['handle']);
        } else {
            return ['isError' => true, 'error' => 'Provide either "id" or "handle".'];
        }

        $form = $query->one();
        if (!$form instanceof Form) {
            return ['isError' => true, 'error' => 'Form not found.'];
        }

        $service = Plugin::getInstance()->getIntegrations();
        $integrations = [];
        foreach ($service->getIntegrationsForForm((int) $form->id) as $integration) {
            $integrations[] = [
                // Deliberately no 'settings' — secrets must never cross the MCP boundary.
                'name' => $integration->name,
                'type' => $integration->type,
                'enabled' => $integration->enabled,
                'health' => $service->getDispatchHealth((int) $integration->id),
            ];
        }

        return [
            'form' => ['id' => (int) $form->id, 'handle' => (string) $form->handle],
            'integrations' => $integrations,
        ];
    }
}

<?php

namespace fabianhaef\simpleform\mcp\tools;

use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\support\FormPresenter;
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
            'properties' => FormPresenter::idOrHandleProperties(),
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
        $form = FormPresenter::resolveByIdOrHandle($arguments);
        if (is_array($form)) {
            return $form;
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

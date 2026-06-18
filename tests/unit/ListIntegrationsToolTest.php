<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\gql\types\FormIntegrationType;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\mcp\tools\ListIntegrationsTool;
use PHPUnit\Framework\TestCase;

class ListIntegrationsToolTest extends TestCase
{
    public function testToolContract(): void
    {
        $tool = new ListIntegrationsTool();
        $this->assertSame('list_integrations', $tool->name());
        $this->assertSame(Scopes::FORMS_MANAGE, $tool->requiredScope());

        $schema = $tool->inputSchema();
        $this->assertArrayHasKey('id', $schema['properties']);
        $this->assertArrayHasKey('handle', $schema['properties']);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function testGraphqlIntegrationTypeExposesNoSettings(): void
    {
        $keys = array_keys(FormIntegrationType::getFieldDefinitions());
        sort($keys);
        $this->assertSame(['enabled', 'name', 'type'], $keys);
        // Hard guarantee: no settings/secret surface on the GraphQL type.
        $this->assertNotContains('settings', $keys);
        $this->assertNotContains('secret', $keys);
    }
}

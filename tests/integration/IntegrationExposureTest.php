<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\models\GqlSchema;
use craft\web\Response;
use fabianhaef\simpleform\controllers\McpController;
use fabianhaef\simpleform\mcp\Scopes;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;

/**
 * #80 — read-only exposure of a form's integrations via GraphQL and MCP, with
 * the hard guarantee that connector settings/secrets never cross either boundary.
 *
 * @group requires-craft
 */
class IntegrationExposureTest extends SimpleFormTestCase
{
    private const SECRET = 'super-secret-signing-key-xyz';

    private function seedFormWithSecretIntegration(string $handle): int
    {
        $form = $this->createForm('Exposure', $handle);
        $integration = new IntegrationModel();
        $integration->formId = (int) $form->id;
        $integration->type = 'webhook';
        $integration->name = 'Ops hook';
        $integration->enabled = true;
        $integration->settings = ['url' => 'https://example.test/hook', 'secret' => self::SECRET];
        Plugin::getInstance()->getIntegrations()->saveIntegration($integration);

        return (int) $form->id;
    }

    public function testGraphqlExposesIntegrationsWithoutSecrets(): void
    {
        $this->requireCraft();
        $handle = 'gqlExposure';
        $this->seedFormWithSecretIntegration($handle);

        $schema = new GqlSchema(['id' => 1, 'uid' => 'expo-uid', 'name' => 'Expo', 'scope' => ['simpleForms:read']]);
        $gql = Craft::$app->getGql();
        $gql->flushCaches();
        $gql->setActiveSchema($schema);

        $document = <<<'GQL'
        query ($handle: String!) {
            simpleForm(handle: $handle) {
                integrations { name type enabled }
            }
        }
        GQL;

        $result = $gql->executeQuery($schema, $document, ['handle' => $handle]);

        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? []));
        $integrations = $result['data']['simpleForm']['integrations'];
        $this->assertCount(1, $integrations);
        $this->assertSame('Ops hook', $integrations[0]['name']);
        $this->assertSame('webhook', $integrations[0]['type']);
        $this->assertTrue($integrations[0]['enabled']);

        // The secret must not appear anywhere in the serialized GraphQL response.
        $this->assertStringNotContainsString(self::SECRET, json_encode($result) ?: '');
    }

    public function testMcpListIntegrationsReturnsHealthWithoutSecrets(): void
    {
        $this->requireCraft();

        // Settings must be valid for MCP to be enabled.
        $plugin = Plugin::getInstance();
        $values = $plugin->getSettings()->getAttributes();
        if (empty($values['defaultEmailSender'])) {
            $values['defaultEmailSender'] = 'test@example.com';
        }
        $values['enableMcp'] = true;
        Craft::$app->getPlugins()->savePluginSettings($plugin, $values);

        $formId = $this->seedFormWithSecretIntegration('mcpExposure');
        $token = $plugin->getMcpTokenManager()->createToken('Forms client', [Scopes::FORMS_MANAGE])['secret'];

        $res = $this->callTool('list_integrations', ['id' => $formId], $token);

        $this->assertFalse($res['result']['isError'] ?? false, json_encode($res));
        $structured = $res['result']['structuredContent'];
        $this->assertCount(1, $structured['integrations']);
        $integration = $structured['integrations'][0];
        $this->assertSame('Ops hook', $integration['name']);
        $this->assertSame('webhook', $integration['type']);
        $this->assertTrue($integration['enabled']);
        $this->assertArrayNotHasKey('settings', $integration);
        $this->assertArrayHasKey('health', $integration);

        // The secret must not appear anywhere in the serialized MCP response.
        $this->assertStringNotContainsString(self::SECRET, json_encode($res) ?: '');
    }

    /**
     * Drive the real McpController for a tools/call, mirroring McpFormToolsTest.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $name, array $arguments, string $bearer): array
    {
        $payload = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => $name, 'arguments' => $arguments],
        ];

        $request = Craft::$app->getRequest();
        $request->setRawBody((string) json_encode($payload));
        $request->setBodyParams([]);
        $request->headers->set('Authorization', "Bearer $bearer");
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new McpController('mcp', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $response = $controller->actionIndex();

        return is_array($response->data) ? $response->data : [];
    }
}

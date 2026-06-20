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
        $integration->type = 'webhook';
        $integration->name = 'Ops hook';
        $integration->enabled = true;
        $integration->settings = ['url' => 'https://example.test/hook', 'secret' => self::SECRET];
        $integrations = Plugin::getInstance()->getIntegrations();
        $integrations->saveIntegration($integration);
        $integrations->toggleFormIntegration((int) $form->id, (int) $integration->id);

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

    public function testIntegrationSecretIsEncryptedAtRest(): void
    {
        $this->requireCraft();

        // F4 needs a securityKey to encrypt; the test env doesn't configure one.
        $general = Craft::$app->getConfig()->getGeneral();
        $original = $general->securityKey;
        $general->securityKey = str_repeat('k', 32);

        try {
            $integration = new IntegrationModel();
            $integration->type = 'webhook';
            $integration->name = 'Enc hook';
            $integration->enabled = true;
            $integration->settings = ['url' => 'https://example.test/hook', 'secret' => self::SECRET];
            $integrations = Plugin::getInstance()->getIntegrations();
            $this->assertTrue($integrations->saveIntegration($integration));

            $raw = (new \craft\db\Query())
                ->select(['settings'])
                ->from('{{%simpleform_integrations}}')
                ->where(['id' => $integration->id])
                ->scalar();
            $raw = is_string($raw) ? $raw : (string) json_encode($raw);
            $this->assertStringNotContainsString(self::SECRET, $raw, 'plaintext secret must not be stored');
            $this->assertStringContainsString('sfenc:', $raw, 'secret should carry the encryption marker');

            // Reading back transparently decrypts.
            $loaded = $integrations->getIntegrationById((int) $integration->id);
            $this->assertSame(self::SECRET, $loaded->settings['secret']);
        } finally {
            $general->securityKey = $original;
        }
    }

    public function testEncryptStoredSecretsBackfillsPlaintextRows(): void
    {
        $this->requireCraft();

        $general = Craft::$app->getConfig()->getGeneral();
        $original = $general->securityKey;
        $integrations = Plugin::getInstance()->getIntegrations();

        try {
            // Simulate a pre-encryption row: with no key, the secret is stored
            // in plaintext (encryption degrades to a no-op).
            $general->securityKey = '';
            $m = new IntegrationModel();
            $m->type = 'webhook';
            $m->name = 'Legacy hook';
            $m->enabled = true;
            $m->settings = ['url' => 'https://example.test/hook', 'secret' => self::SECRET];
            $this->assertTrue($integrations->saveIntegration($m));

            $raw = (new \craft\db\Query())->select(['settings'])
                ->from('{{%simpleform_integrations}}')->where(['id' => $m->id])->scalar();
            $this->assertStringContainsString(self::SECRET, (string) $raw, 'precondition: stored plaintext');

            // Backfill once a key is configured.
            $general->securityKey = str_repeat('k', 32);
            $this->assertGreaterThanOrEqual(1, $integrations->encryptStoredSecrets());

            $raw2 = (new \craft\db\Query())->select(['settings'])
                ->from('{{%simpleform_integrations}}')->where(['id' => $m->id])->scalar();
            $this->assertStringNotContainsString(self::SECRET, (string) $raw2);
            $this->assertStringContainsString('sfenc:', (string) $raw2);

            // Still decrypts transparently, and the backfill is idempotent.
            $loaded = $integrations->getIntegrationById((int) $m->id);
            $this->assertSame(self::SECRET, $loaded->settings['secret']);
            $this->assertSame(0, $integrations->encryptStoredSecrets());
        } finally {
            $general->securityKey = $original;
        }
    }

    public function testDispatchLogRedactsConnectorSecret(): void
    {
        $this->requireCraft();

        // F7: a remote error body echoing our own secret must be redacted before
        // it is written to the dispatch log.
        $service = Plugin::getInstance()->getIntegrations();
        $method = new \ReflectionMethod($service, 'scrubSecrets');
        $method->setAccessible(true);

        $message = $method->invoke($service, 'rejected token=' . self::SECRET . ' (bad)', ['secret' => self::SECRET]);
        $this->assertStringNotContainsString(self::SECRET, $message);
        $this->assertStringContainsString('[redacted]', $message);
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

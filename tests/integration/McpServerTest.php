<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\controllers\McpController;
use fabianhaef\simpleform\mcp\McpServer;
use fabianhaef\simpleform\mcp\Scopes;
use craft\web\Response;
use fabianhaef\simpleform\Plugin;

/**
 * End-to-end coverage of the MCP foundation: the JSON-RPC handshake, tool
 * listing, scope-gated tool dispatch, bearer auth, and the off-by-default
 * toggle.
 *
 * Tests drive the real {@see McpController} action by constructing a request
 * (raw JSON-RPC body + Authorization header) and invoking the action, then
 * assert on the JSON-RPC response — the same path a real MCP client exercises.
 *
 * @group requires-craft
 */
class McpServerTest extends SimpleFormTestCase
{
    protected function _before(): void
    {
        parent::_before();
        // Persisting MCP tokens routes through savePluginSettings(), which
        // validates the whole Settings model. defaultEmailSender is required, so
        // seed it for the test environment (in production it is configured by the
        // operator). Changes roll back with the per-test DB transaction.
        if (class_exists(\Craft::class) && Craft::$app !== null) {
            $plugin = Plugin::getInstance();
            $values = $plugin->getSettings()->getAttributes();
            if (empty($values['defaultEmailSender'])) {
                $values['defaultEmailSender'] = 'test@example.com';
                Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
            }
        }
    }

    /**
     * Issue a token with the given scopes and return its plaintext secret.
     *
     * @param list<string> $scopes
     */
    private function issueToken(array $scopes, string $label = 'Test client'): string
    {
        return Plugin::getInstance()->getMcpTokenManager()->createToken($label, $scopes)['secret'];
    }

    /**
     * Invoke the MCP controller action with a JSON-RPC payload + optional bearer
     * token, returning [statusCode, decodedJsonRpcResponse].
     *
     * @param array<string, mixed> $payload
     * @return array{0:int, 1:array<string, mixed>|null}
     */
    private function dispatch(array $payload, ?string $bearer = null): array
    {
        // Reset the request/response so each call is isolated.
        $request = Craft::$app->getRequest();
        $request->setRawBody((string) json_encode($payload));
        $request->setBodyParams([]);
        $request->headers->set('Authorization', $bearer !== null ? "Bearer $bearer" : '');
        // Force POST. getMethod()/getIsPost() read $_SERVER['REQUEST_METHOD'].
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // Give the app a fresh craft\web\Response so the controller's
        // getResponse() returns the expected type and our assertions read its
        // ->data directly (the action sets ->data, not serialised content).
        Craft::$app->set('response', new Response());

        $controller = new McpController('mcp', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $response = $controller->actionIndex();

        $decoded = $response->data;

        return [$response->getStatusCode(), is_array($decoded) ? $decoded : null];
    }

    private function enableMcp(bool $on): void
    {
        $plugin = Plugin::getInstance();
        $values = $plugin->getSettings()->getAttributes();
        $values['enableMcp'] = $on;
        Craft::$app->getPlugins()->savePluginSettings($plugin, $values);
    }

    public function testInitializeReturnsToolsCapability(): void
    {
        $this->requireCraft();
        $this->enableMcp(true);
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        [$status, $res] = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => McpServer::PROTOCOL_VERSION, 'capabilities' => []],
        ], $token);

        $this->assertSame(200, $status);
        $this->assertSame(McpServer::PROTOCOL_VERSION, $res['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $res['result']['capabilities']);
        $this->assertSame('simple-form-mcp', $res['result']['serverInfo']['name']);
    }

    public function testToolsListIncludesListFormsWithSchema(): void
    {
        $this->requireCraft();
        $this->enableMcp(true);
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        [$status, $res] = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
        ], $token);

        $this->assertSame(200, $status);
        $tools = $res['result']['tools'];
        $names = array_column($tools, 'name');
        $this->assertContains('list_forms', $names);

        $listForms = $tools[array_search('list_forms', $names, true)];
        $this->assertSame('object', $listForms['inputSchema']['type']);
        $this->assertNotEmpty($listForms['description']);
    }

    public function testToolsCallListFormsReturnsForms(): void
    {
        $this->requireCraft();
        $this->enableMcp(true);

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm('MCP Contact', 'mcpContactForm', 'MCP Contact', $siteId);
        $this->createField($form->id, 'text', 'fullName', 'Full Name', true);
        $this->createField($form->id, 'email', 'email', 'Email', true);

        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        [$status, $res] = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => ['name' => 'list_forms', 'arguments' => []],
        ], $token);

        $this->assertSame(200, $status);
        $this->assertFalse($res['result']['isError']);

        $structured = $res['result']['structuredContent'];
        $byHandle = array_column($structured['forms'], null, 'handle');
        $this->assertArrayHasKey('mcpContactForm', $byHandle);
        $this->assertSame((int) $form->id, $byHandle['mcpContactForm']['id']);
        $this->assertSame('MCP Contact', $byHandle['mcpContactForm']['name']);
        $this->assertSame(2, $byHandle['mcpContactForm']['fieldCount']);

        // Backwards-compat: the structured result is also serialised to text.
        $this->assertSame('text', $res['result']['content'][0]['type']);
    }

    public function testMissingTokenIsRejected(): void
    {
        $this->requireCraft();
        $this->enableMcp(true);

        [$status, $res] = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'tools/list',
        ], null);

        $this->assertSame(401, $status);
        $this->assertArrayHasKey('error', $res);
    }

    public function testInvalidTokenIsRejected(): void
    {
        $this->requireCraft();
        $this->enableMcp(true);

        [$status, $res] = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 5,
            'method' => 'tools/list',
        ], 'sfmcp_not-a-real-token');

        $this->assertSame(401, $status);
        $this->assertArrayHasKey('error', $res);
    }

    public function testTokenLackingScopeIsRejected(): void
    {
        $this->requireCraft();
        $this->enableMcp(true);

        // Token holds only an unrelated scope, not forms:manage.
        $token = $this->issueToken([Scopes::SUBMISSIONS_READ]);

        [$status, $res] = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 6,
            'method' => 'tools/call',
            'params' => ['name' => 'list_forms', 'arguments' => []],
        ], $token);

        // Authenticated (200 transport) but the JSON-RPC call is a scope error.
        $this->assertSame(200, $status);
        $this->assertArrayHasKey('error', $res);
        $this->assertSame(-32001, $res['error']['code']);
        // Generic message: must not name the missing scope.
        $this->assertStringNotContainsString('forms:manage', $res['error']['message']);
    }

    public function testDisabledServerRefuses(): void
    {
        $this->requireCraft();
        $this->enableMcp(false);
        $token = $this->issueToken([Scopes::FORMS_MANAGE]);

        [$status, $res] = $this->dispatch([
            'jsonrpc' => '2.0',
            'id' => 7,
            'method' => 'tools/list',
        ], $token);

        $this->assertSame(404, $status);
    }

    public function testTokenStoredHashedNotPlaintext(): void
    {
        $this->requireCraft();

        $secret = $this->issueToken([Scopes::FORMS_MANAGE], 'Hashing check');
        $stored = Plugin::getInstance()->getSettings()->mcpTokens;

        $this->assertNotEmpty($stored);
        foreach ($stored as $entry) {
            // The plaintext secret must never appear at rest.
            $this->assertNotSame($secret, $entry['hash'] ?? null);
            $this->assertArrayNotHasKey('secret', $entry);
            // Hash is a 64-char hex SHA-256 digest.
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) ($entry['hash'] ?? ''));
        }
    }
}

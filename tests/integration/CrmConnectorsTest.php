<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\HubSpotIntegration;
use anvildev\simpleform\integrations\PipedriveIntegration;
use anvildev\simpleform\Plugin;
use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

class MockHubSpot extends HubSpotIntegration
{
    /** @var list<array<string, mixed>> */
    public array $history = [];

    public function __construct(private MockHandler $mock)
    {
    }

    protected function httpClient(): Client
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new Client(['handler' => $stack]);
    }
}

class MockPipedrive extends PipedriveIntegration
{
    /** @var list<array<string, mixed>> */
    public array $history = [];

    public function __construct(private MockHandler $mock)
    {
    }

    protected function httpClient(): Client
    {
        $stack = HandlerStack::create($this->mock);
        $stack->push(Middleware::history($this->history));
        return new Client(['handler' => $stack]);
    }
}

/**
 * Request-shape coverage for the CRM connectors against a real submission.
 *
 * @group requires-craft
 */
class CrmConnectorsTest extends SimpleFormTestCase
{
    private function submission(string $handle, bool $withEmail = true): Submission
    {
        $form = $this->createForm('CRM', $handle);
        $nameField = $this->createField($form->id, 'text', 'fullName', 'Name');
        $data = ['field_' . $nameField => ['label' => 'Name', 'type' => 'text', 'value' => 'Ada Lovelace']];

        if ($withEmail) {
            $emailField = $this->createField($form->id, 'email', 'emailAddress', 'Email');
            $data['field_' . $emailField] = ['label' => 'Email', 'type' => 'email', 'value' => 'ada@example.test'];
        }

        $sub = new Submission();
        $sub->formId = (int) $form->id;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = $data;
        $sub->readStatus = 'new';
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        return $sub;
    }

    public function testConnectorsAreRegistered(): void
    {
        $this->requireCraft();
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();
        $this->assertInstanceOf(HubSpotIntegration::class, $registry->getType('hubspot'));
        $this->assertInstanceOf(PipedriveIntegration::class, $registry->getType('pipedrive'));
    }

    public function testHubSpotCreatesContactWithBearerAndEmail(): void
    {
        $this->requireCraft();
        $sub = $this->submission('hs_ok');

        $hs = new MockHubSpot(new MockHandler([new Response(201, [], '{"id":"1"}')]));
        $result = $hs->send($sub, ['apiToken' => 'pat-123', 'objectType' => 'contacts']);

        $this->assertTrue($result->success);
        $request = $hs->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://api.hubapi.com/crm/v3/objects/contacts', (string) $request->getUri());
        $this->assertSame('Bearer pat-123', $request->getHeaderLine('Authorization'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('ada@example.test', $body['properties']['email']);
    }

    public function testHubSpotContactWithoutEmailFails(): void
    {
        $this->requireCraft();
        $sub = $this->submission('hs_noemail', withEmail: false);

        $hs = new MockHubSpot(new MockHandler([]));
        $result = $hs->send($sub, ['apiToken' => 'pat-123', 'objectType' => 'contacts']);

        $this->assertFalse($result->success);
        $this->assertCount(0, $hs->history);
    }

    public function testPipedriveCreatesPersonWithTokenHeaderAndName(): void
    {
        $this->requireCraft();
        $sub = $this->submission('pd_ok');

        $pd = new MockPipedrive(new MockHandler([new Response(201, [], '{"success":true}')]));
        $result = $pd->send($sub, [
            'apiDomain' => 'https://acme.pipedrive.com/',
            'apiToken' => 'tok',
            'nameField' => 'fullName',
        ]);

        $this->assertTrue($result->success);
        $request = $pd->history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('acme.pipedrive.com', $request->getUri()->getHost());
        $this->assertSame('/v1/persons', $request->getUri()->getPath());
        // F5: the token is sent in a header, never in the URL/query string.
        $this->assertStringNotContainsString('api_token', $request->getUri()->getQuery());
        $this->assertSame('tok', $request->getHeaderLine('x-api-token'));
        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('Ada Lovelace', $body['name']);
        $this->assertSame('ada@example.test', $body['email']);
    }
}

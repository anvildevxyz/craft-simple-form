<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\ActiveCampaignIntegration;
use anvildev\simpleform\integrations\MailchimpIntegration;
use anvildev\simpleform\Plugin;
use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/** Mailchimp with a mocked, request-capturing HTTP client. */
class MockMailchimp extends MailchimpIntegration
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

/** ActiveCampaign with a mocked, request-capturing HTTP client. */
class MockActiveCampaign extends ActiveCampaignIntegration
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
 * Request-shape coverage for the email-marketing connectors against a real
 * submission, with a Guzzle mock asserting the outgoing API calls.
 *
 * @group requires-craft
 */
class MarketingConnectorsTest extends SimpleFormTestCase
{
    private function submissionWithEmail(string $handle, ?string $email = 'ada@example.test'): Submission
    {
        $form = $this->createForm('Marketing', $handle);
        $emailField = $this->createField($form->id, 'email', 'emailAddress', 'Email');

        $data = [];
        if ($email !== null) {
            $data['field_' . $emailField] = ['label' => 'Email', 'type' => 'email', 'value' => $email];
        } else {
            $nameField = $this->createField($form->id, 'text', 'fullName', 'Name');
            $data['field_' . $nameField] = ['label' => 'Name', 'type' => 'text', 'value' => 'Ada'];
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
        $this->assertInstanceOf(MailchimpIntegration::class, $registry->getType('mailchimp'));
        $this->assertInstanceOf(ActiveCampaignIntegration::class, $registry->getType('activecampaign'));
    }

    public function testMailchimpUpsertsMemberWithCorrectRequest(): void
    {
        $this->requireCraft();
        $sub = $this->submissionWithEmail('mc_ok');

        $mc = new MockMailchimp(new MockHandler([new Response(200, [], '{}')]));
        $result = $mc->send($sub, ['apiKey' => 'secret-us5', 'audienceId' => 'aud123', 'doubleOptIn' => true]);

        $this->assertTrue($result->success);
        $this->assertCount(1, $mc->history);
        $request = $mc->history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());

        $expectedHash = MailchimpIntegration::subscriberHash('ada@example.test');
        $this->assertSame(
            "https://us5.api.mailchimp.com/3.0/lists/aud123/members/$expectedHash",
            (string) $request->getUri(),
        );
        $this->assertStringContainsString('Basic ', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('ada@example.test', $body['email_address']);
        $this->assertSame('pending', $body['status_if_new']); // double opt-in
    }

    public function testMailchimpFailsWithoutEmailAndMakesNoCall(): void
    {
        $this->requireCraft();
        $sub = $this->submissionWithEmail('mc_noemail', null);

        $mc = new MockMailchimp(new MockHandler([])); // no responses queued
        $result = $mc->send($sub, ['apiKey' => 'secret-us5', 'audienceId' => 'aud123']);

        $this->assertFalse($result->success);
        $this->assertCount(0, $mc->history, 'must not hit the API when no email is present');
    }

    public function testActiveCampaignSyncsContactThenAddsToList(): void
    {
        $this->requireCraft();
        $sub = $this->submissionWithEmail('ac_ok');

        $ac = new MockActiveCampaign(new MockHandler([
            new Response(200, [], '{"contact":{"id":"42"}}'),
            new Response(200, [], '{}'),
        ]));
        $result = $ac->send($sub, [
            'apiUrl' => 'https://acme.api-us1.com/',
            'apiKey' => 'tok',
            'listId' => '7',
        ]);

        $this->assertTrue($result->success);
        $this->assertCount(2, $ac->history);

        $sync = $ac->history[0]['request'];
        $this->assertSame('POST', $sync->getMethod());
        $this->assertSame('https://acme.api-us1.com/api/3/contact/sync', (string) $sync->getUri());
        $this->assertSame('tok', $sync->getHeaderLine('Api-Token'));
        $syncBody = json_decode((string) $sync->getBody(), true);
        $this->assertSame('ada@example.test', $syncBody['contact']['email']);

        $listAdd = $ac->history[1]['request'];
        $this->assertSame('https://acme.api-us1.com/api/3/contactLists', (string) $listAdd->getUri());
        $listBody = json_decode((string) $listAdd->getBody(), true);
        $this->assertSame('7', $listBody['contactList']['list']);
        $this->assertSame('42', $listBody['contactList']['contact']);
    }
}

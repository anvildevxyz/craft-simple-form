<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\DiscordIntegration;
use fabianhaef\simpleform\integrations\SlackIntegration;
use fabianhaef\simpleform\Plugin;

/**
 * Payload construction for the Slack/Discord connectors against a real
 * submission (handle/label resolution needs Craft). The HTTP transport is
 * unit-tested with a Guzzle mock in ChatIntegrationTest.
 *
 * @group requires-craft
 */
class ChatConnectorsTest extends SimpleFormTestCase
{
    private function seedSubmission(): Submission
    {
        $form = $this->createForm('Chat Form', 'chat_form');
        $nameField = $this->createField($form->id, 'text', 'fullName', 'Name');

        $sub = new Submission();
        $sub->formId = (int) $form->id;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = [
            'field_' . $nameField => ['label' => 'Name', 'type' => 'text', 'value' => 'Ada'],
        ];
        $sub->readStatus = 'new';
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        return $sub;
    }

    public function testConnectorsAreRegistered(): void
    {
        $this->requireCraft();
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();
        $this->assertInstanceOf(SlackIntegration::class, $registry->getType('slack'));
        $this->assertInstanceOf(DiscordIntegration::class, $registry->getType('discord'));
    }

    public function testSlackPayloadHasMessageAndOverrides(): void
    {
        $this->requireCraft();
        $sub = $this->seedSubmission();

        $payload = (new SlackIntegration())->buildPayload($sub, [
            'username' => 'Form Bot',
            'channel' => '#leads',
        ]);

        $this->assertStringContainsString('Name: Ada', $payload['text']);
        $this->assertSame('Form Bot', $payload['username']);
        $this->assertSame('#leads', $payload['channel']);
    }

    public function testSlackTemplateOverridesAutoMessage(): void
    {
        $this->requireCraft();
        $sub = $this->seedSubmission();

        $payload = (new SlackIntegration())->buildPayload($sub, [
            'messageTemplate' => 'New lead: {fullName}',
        ]);

        $this->assertSame('New lead: Ada', $payload['text']);
    }

    public function testDiscordPayloadUsesContent(): void
    {
        $this->requireCraft();
        $sub = $this->seedSubmission();

        $payload = (new DiscordIntegration())->buildPayload($sub, ['username' => 'Bot']);

        $this->assertStringContainsString('Name: Ada', $payload['content']);
        $this->assertSame('Bot', $payload['username']);
        $this->assertLessThanOrEqual(2000, mb_strlen($payload['content']));
    }
}

<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\WebhookIntegration;

/**
 * Payload construction needs a real form (id -> handle resolution), so it lives
 * in the integration suite. The HTTP transport itself is unit-tested with a
 * Guzzle mock in WebhookIntegrationTest.
 *
 * @group requires-craft
 */
class WebhookPayloadTest extends SimpleFormTestCase
{
    private function submissionFor(int $formId, int $nameField, int $emailField): Submission
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = [
            'field_' . $nameField => ['label' => 'Name', 'type' => 'text', 'value' => 'Ada'],
            'field_' . $emailField => ['label' => 'Email', 'type' => 'email', 'value' => 'ada@example.test'],
        ];
        $sub->readStatus = 'new';
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        return $sub;
    }

    public function testPayloadIsKeyedByFieldHandleWithMetadata(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Webhook Form', 'webhook_form');
        $nameField = $this->createField($form->id, 'text', 'fullName', 'Name');
        $emailField = $this->createField($form->id, 'email', 'emailAddress', 'Email');
        $sub = $this->submissionFor((int) $form->id, $nameField, $emailField);

        $payload = (new WebhookIntegration())->buildPayload($sub, []);

        $this->assertSame('webhook_form', $payload['formHandle']);
        $this->assertSame((int) $sub->id, $payload['submissionId']);
        $this->assertSame('Ada', $payload['data']['fullName']);
        $this->assertSame('ada@example.test', $payload['data']['emailAddress']);
    }

    public function testFieldMappingRenamesAndFilters(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Webhook Map', 'webhook_map');
        $nameField = $this->createField($form->id, 'text', 'fullName', 'Name');
        $emailField = $this->createField($form->id, 'email', 'emailAddress', 'Email');
        $sub = $this->submissionFor((int) $form->id, $nameField, $emailField);

        $payload = (new WebhookIntegration())->buildPayload($sub, [
            'fieldMapping' => ['emailAddress' => 'contact_email'],
        ]);

        // Only the mapped field is present, renamed to its target key.
        $this->assertSame(['contact_email' => 'ada@example.test'], $payload['data']);
    }
}

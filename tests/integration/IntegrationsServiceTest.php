<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\integrations\DispatchStatus;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;

/**
 * Proves the integrations migration applied and the service round-trips global
 * integration definitions, their per-form attachments, and the dispatch log,
 * including the form-delete cascade of attachments and submission-delete
 * set-null.
 */
class IntegrationsServiceTest extends SimpleFormTestCase
{
    private function makeIntegration(string $name, bool $enabled = true): IntegrationModel
    {
        $model = new IntegrationModel();
        $model->type = 'webhook';
        $model->name = $name;
        $model->enabled = $enabled;
        $model->settings = ['url' => 'https://example.test/hook'];
        $this->assertTrue(Plugin::getInstance()->getIntegrations()->saveIntegration($model));
        $this->assertNotNull($model->id);
        return $model;
    }

    public function testSaveAndFetchIntegration(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();

        $model = $this->makeIntegration('Zapier hook');

        $fetched = $service->getIntegrationById($model->id);
        $this->assertNotNull($fetched);
        $this->assertSame('webhook', $fetched->type);
        $this->assertSame('Zapier hook', $fetched->name);
        $this->assertSame('https://example.test/hook', $fetched->settings['url']);
    }

    public function testAttachmentDrivesPerFormDispatchSet(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'contact_int_enabled');
        $service = Plugin::getInstance()->getIntegrations();
        $formId = (int) $form->id;

        $on = $this->makeIntegration('On', true);
        $off = $this->makeIntegration('Off', false);

        // Nothing attached yet.
        $this->assertCount(0, $service->getIntegrationsForForm($formId));

        $this->assertTrue($service->toggleFormIntegration($formId, $on->id));
        $this->assertTrue($service->toggleFormIntegration($formId, $off->id));

        // Both attached, but only the globally-enabled one is in the dispatch set.
        $this->assertCount(2, $service->getIntegrationsForForm($formId));
        $enabled = $service->getEnabledIntegrationsForForm($formId);
        $this->assertCount(1, $enabled);
        $this->assertSame('On', $enabled[0]->name);

        // Flipping the global switch off removes it from the dispatch set without
        // detaching it.
        $on->enabled = false;
        $this->assertTrue($service->saveIntegration($on));
        $this->assertCount(2, $service->getIntegrationsForForm($formId));
        $this->assertCount(0, $service->getEnabledIntegrationsForForm($formId));
    }

    public function testToggleFormIntegrationDetaches(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'contact_int_detach');
        $service = Plugin::getInstance()->getIntegrations();
        $formId = (int) $form->id;
        $hook = $this->makeIntegration('Hook');

        $this->assertTrue($service->toggleFormIntegration($formId, $hook->id));
        $this->assertSame([$hook->id], $service->getAttachedIntegrationIds($formId));

        // Toggling again detaches.
        $this->assertFalse($service->toggleFormIntegration($formId, $hook->id));
        $this->assertSame([], $service->getAttachedIntegrationIds($formId));
    }

    public function testInvalidIntegrationFailsValidation(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();

        $model = new IntegrationModel(); // missing type/name
        $this->assertFalse($service->saveIntegration($model));
    }

    public function testLogDispatchAndFetchBySubmission(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();

        $model = $this->makeIntegration('Hook');

        // No real submission needed for the log query; submissionId is nullable.
        $logId = $service->logDispatch($model->id, null, DispatchStatus::FAILED, 2, 500, 'server error');
        $this->assertGreaterThan(0, $logId);

        $row = (new \craft\db\Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['id' => $logId])
            ->one();
        $this->assertSame('failed', $row['status']);
        $this->assertSame(500, (int) $row['responseCode']);
    }

    public function testInvalidStatusFallsBackToPending(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();

        $model = $this->makeIntegration('Hook');

        $logId = $service->logDispatch($model->id, null, 'bogus-status');
        $row = (new \craft\db\Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['id' => $logId])
            ->one();
        $this->assertSame(DispatchStatus::PENDING, $row['status']);
    }

    public function testGetAllIntegrationsReturnsEveryDefinition(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();

        foreach (['A1', 'A2', 'B1'] as $name) {
            $this->makeIntegration($name);
        }

        $names = array_map(static fn(IntegrationModel $i): string => $i->name, $service->getAllIntegrations());
        foreach (['A1', 'A2', 'B1'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function testValidateSettingsRejectsMissingWebhookUrl(): void
    {
        $this->requireCraft();
        $type = Plugin::getInstance()->getIntegrationTypeRegistry()->getType('webhook');
        $this->assertNotNull($type);

        $errors = Plugin::getInstance()->getIntegrations()->validateSettings($type, ['method' => 'POST']);
        $this->assertArrayHasKey('url', $errors);
    }

    public function testValidateSettingsAcceptsValidWebhook(): void
    {
        $this->requireCraft();
        $type = Plugin::getInstance()->getIntegrationTypeRegistry()->getType('webhook');
        $this->assertNotNull($type);

        $errors = Plugin::getInstance()->getIntegrations()->validateSettings($type, [
            'url' => 'https://example.test/hook',
            'method' => 'POST',
            'format' => 'json',
        ]);
        $this->assertSame([], $errors);
    }

    public function testValidateSettingsRejectsBadMethod(): void
    {
        $this->requireCraft();
        $type = Plugin::getInstance()->getIntegrationTypeRegistry()->getType('webhook');
        $this->assertNotNull($type);

        $errors = Plugin::getInstance()->getIntegrations()->validateSettings($type, [
            'url' => 'https://example.test/hook',
            'method' => 'DELETE',
        ]);
        $this->assertArrayHasKey('method', $errors);
    }

    public function testDeletingFormCascadesAttachmentsNotDefinitions(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'contact_int_cascade');
        $service = Plugin::getInstance()->getIntegrations();
        $formId = (int) $form->id;

        $model = $this->makeIntegration('Hook');
        $service->toggleFormIntegration($formId, $model->id);
        $this->assertCount(1, $service->getIntegrationsForForm($formId));

        Craft::$app->getElements()->deleteElement($form, true);

        // The attachment is gone, but the global definition survives.
        $this->assertCount(0, $service->getIntegrationsForForm($formId));
        $this->assertNotNull($service->getIntegrationById($model->id));
    }
}

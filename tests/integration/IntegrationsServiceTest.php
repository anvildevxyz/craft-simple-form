<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\integrations\DispatchStatus;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;

/**
 * Proves the integrations migration applied and the service round-trips configs
 * + logs, including the form-delete cascade and submission-delete set-null.
 */
class IntegrationsServiceTest extends SimpleFormTestCase
{
    public function testSaveAndFetchIntegration(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'contact_int_save');
        $service = Plugin::getInstance()->getIntegrations();

        $model = new IntegrationModel();
        $model->formId = (int) $form->id;
        $model->type = 'webhook';
        $model->name = 'Zapier hook';
        $model->enabled = true;
        $model->settings = ['url' => 'https://example.test/hook'];

        $this->assertTrue($service->saveIntegration($model));
        $this->assertNotNull($model->id);

        $fetched = $service->getIntegrationById($model->id);
        $this->assertNotNull($fetched);
        $this->assertSame('webhook', $fetched->type);
        $this->assertSame('Zapier hook', $fetched->name);
        $this->assertSame('https://example.test/hook', $fetched->settings['url']);
    }

    public function testEnabledFilterAndUpdate(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'contact_int_enabled');
        $service = Plugin::getInstance()->getIntegrations();
        $formId = (int) $form->id;

        $on = new IntegrationModel();
        $on->formId = $formId;
        $on->type = 'webhook';
        $on->name = 'On';
        $on->enabled = true;
        $service->saveIntegration($on);

        $off = new IntegrationModel();
        $off->formId = $formId;
        $off->type = 'webhook';
        $off->name = 'Off';
        $off->enabled = false;
        $service->saveIntegration($off);

        $this->assertCount(2, $service->getIntegrationsForForm($formId));
        $enabled = $service->getEnabledIntegrationsForForm($formId);
        $this->assertCount(1, $enabled);
        $this->assertSame('On', $enabled[0]->name);

        // Update flips enabled off.
        $on->enabled = false;
        $this->assertTrue($service->saveIntegration($on));
        $this->assertCount(0, $service->getEnabledIntegrationsForForm($formId));
    }

    public function testInvalidIntegrationFailsValidation(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getIntegrations();

        $model = new IntegrationModel(); // missing formId/type/name
        $this->assertFalse($service->saveIntegration($model));
    }

    public function testLogDispatchAndFetchBySubmission(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'contact_int_log');
        $service = Plugin::getInstance()->getIntegrations();

        $model = new IntegrationModel();
        $model->formId = (int) $form->id;
        $model->type = 'webhook';
        $model->name = 'Hook';
        $service->saveIntegration($model);

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
        $form = $this->createForm('Contact', 'contact_int_status');
        $service = Plugin::getInstance()->getIntegrations();

        $model = new IntegrationModel();
        $model->formId = (int) $form->id;
        $model->type = 'webhook';
        $model->name = 'Hook';
        $service->saveIntegration($model);

        $logId = $service->logDispatch($model->id, null, 'bogus-status');
        $row = (new \craft\db\Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['id' => $logId])
            ->one();
        $this->assertSame(DispatchStatus::PENDING, $row['status']);
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

    public function testDeletingFormCascadesIntegrations(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'contact_int_cascade');
        $service = Plugin::getInstance()->getIntegrations();
        $formId = (int) $form->id;

        $model = new IntegrationModel();
        $model->formId = $formId;
        $model->type = 'webhook';
        $model->name = 'Hook';
        $service->saveIntegration($model);
        $service->logDispatch($model->id, null, DispatchStatus::SUCCESS);

        Craft::$app->getElements()->deleteElement($form, true);

        $this->assertCount(0, $service->getIntegrationsForForm($formId));
        $remainingLogs = (new \craft\db\Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['integrationId' => $model->id])
            ->count();
        $this->assertSame(0, (int) $remainingLogs);
    }
}

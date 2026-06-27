<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\models\NotificationModel;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\FormPortabilityService;
use anvildev\simpleform\services\IntegrationsService;
use Craft;
use yii\base\InvalidArgumentException;

/**
 * Round-trips a form through {@see FormPortabilityService}: export → import yields
 * an identically-structured form with new ids, secrets never leave, conflict modes
 * behave, integrations re-attach or land as disabled placeholders, and an unknown
 * future schema version aborts cleanly (#139).
 */
class FormPortabilityTest extends SimpleFormTestCase
{
    private function service(): FormPortabilityService
    {
        return Plugin::getInstance()->getPortability();
    }

    /**
     * Build a rich fixture form: a select with options + conditional logic, a
     * notification, and a webhook integration carrying a secret.
     */
    private function seedRichForm(string $handle): Form
    {
        $form = $this->createForm('Contact', $handle, 'Contact', null, 'team@example.test', 'New message');
        $formId = (int) $form->id;

        $this->createField($formId, 'email', 'email', 'Email', true);
        $this->createField($formId, 'dropdown', 'topic', 'Topic', false, [
            'options' => [
                ['value' => 'sales', 'label' => 'Sales'],
                ['value' => 'support', 'label' => 'Support'],
            ],
        ]);
        // A field shown only when topic = support.
        $this->createField($formId, 'textarea', 'details', 'Details', false, [
            'conditional' => [
                'enabled' => true, 'action' => 'show', 'match' => 'all',
                'rules' => [['field' => 'topic', 'operator' => 'eq', 'value' => 'support']],
            ],
        ]);

        $notification = new NotificationModel();
        $notification->formId = $formId;
        $notification->name = 'Admin alert';
        $notification->recipient = 'admin@example.test';
        $notification->subject = 'New submission';
        Plugin::getInstance()->getNotifications()->save($notification);

        $integration = new IntegrationModel();
        $integration->type = 'webhook';
        $integration->name = 'Zapier hook';
        $integration->settings = ['url' => 'https://hooks.example.test/x', 'apiKey' => 'sk-live-SECRET'];
        Plugin::getInstance()->getIntegrations()->saveIntegration($integration);
        Plugin::getInstance()->getIntegrations()->toggleFormIntegration($formId, (int) $integration->id);

        return $form;
    }

    public function testExportShapeAndHandleKeying(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_export');

        $data = $this->service()->export($form);

        $this->assertSame(FormPortabilityService::SCHEMA_VERSION, $data['_meta']['schemaVersion']);
        $this->assertSame('port_export', $data['form']['handle']);

        // Fields are handle-keyed (no ids) and config carries conditional logic.
        $handles = array_column($data['fields'], 'handle');
        $this->assertSame(['email', 'topic', 'details'], $handles);
        $details = $data['fields'][2];
        $this->assertArrayNotHasKey('id', $details);
        $this->assertSame('topic', $details['config']['conditional']['rules'][0]['field']);

        // Per-site content keyed by site handle.
        $primaryHandle = Craft::$app->getSites()->getPrimarySite()->handle;
        $this->assertArrayHasKey($primaryHandle, $data['form']['content']);
    }

    public function testSecretNeverLeavesExport(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_secret');

        $json = $this->service()->exportJson($form);

        $this->assertStringNotContainsString('sk-live-SECRET', $json);
        $this->assertStringContainsString(IntegrationsService::REDACTED, $json);

        $data = $this->service()->export($form);
        $this->assertSame(IntegrationsService::REDACTED, $data['integrations'][0]['settings']['apiKey']);
        $this->assertSame('https://hooks.example.test/x', $data['integrations'][0]['settings']['url']);
    }

    public function testRoundTripRecreatesStructure(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_roundtrip');
        $data = $this->service()->export($form);

        $result = $this->service()->import($data, ['mode' => FormPortabilityService::MODE_RENAME]);
        $new = $result->form;
        $this->assertNotNull($new);
        $this->assertNotSame((int) $form->id, (int) $new->id);
        $this->assertSame('port_roundtrip-2', $new->handle);

        $newFields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $new->id, (int) $new->siteId);
        $this->assertSame(['email', 'topic', 'details'], array_column($newFields, 'name'));

        // Conditional logic re-bound by handle.
        $details = array_values(array_filter($newFields, static fn($f) => $f['name'] === 'details'))[0];
        $this->assertSame('topic', $details['config']['conditional']['rules'][0]['field']);

        // Notification recreated.
        $notifications = Plugin::getInstance()->getNotifications()->getForForm((int) $new->id);
        $this->assertCount(1, $notifications);
        $this->assertSame('admin@example.test', $notifications[0]->recipient);
    }

    public function testRenameModeDerivesUniqueHandle(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_rename');
        $data = $this->service()->export($form);

        $first = $this->service()->import($data, ['mode' => FormPortabilityService::MODE_RENAME]);
        $second = $this->service()->import($data, ['mode' => FormPortabilityService::MODE_RENAME]);

        $this->assertSame('port_rename-2', $first->form?->handle);
        $this->assertSame('port_rename-3', $second->form?->handle);
    }

    public function testAbortModeThrowsOnCollision(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_abort');
        $data = $this->service()->export($form);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->import($data, ['mode' => FormPortabilityService::MODE_ABORT]);
    }

    public function testReplaceModeOverwritesExisting(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_replace');
        $data = $this->service()->export($form);
        $originalId = (int) $form->id;

        $result = $this->service()->import($data, ['mode' => FormPortabilityService::MODE_REPLACE]);

        $this->assertSame('port_replace', $result->form?->handle);
        $this->assertNotSame($originalId, (int) $result->form?->id);
        // Only one form with that handle survives.
        $this->assertCount(1, Form::find()->handle('port_replace')->siteId('*')->status(null)->all());
    }

    public function testIntegrationReattachesByTypeAndName(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_reattach');
        $data = $this->service()->export($form);

        // The global "Zapier hook" already exists, so import re-attaches it.
        $result = $this->service()->import($data, ['mode' => FormPortabilityService::MODE_RENAME]);
        $attached = Plugin::getInstance()->getIntegrations()->getIntegrationsForForm((int) $result->form?->id);
        $this->assertCount(1, $attached);
        $this->assertTrue($attached[0]->enabled);
        $this->assertSame('Zapier hook', $attached[0]->name);
    }

    public function testUnmatchedIntegrationBecomesDisabledPlaceholder(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_placeholder');
        $data = $this->service()->export($form);
        // Rename the integration reference so no local match exists.
        $data['integrations'][0]['name'] = 'Nonexistent hook';

        $result = $this->service()->import($data, ['mode' => FormPortabilityService::MODE_RENAME]);

        $attached = Plugin::getInstance()->getIntegrations()->getIntegrationsForForm((int) $result->form?->id);
        $this->assertCount(1, $attached);
        $this->assertFalse($attached[0]->enabled, 'Placeholder must be disabled');
        $this->assertSame('', $attached[0]->settings['apiKey'] ?? null, 'Redacted secret must be blanked');
        $this->assertNotEmpty($result->warnings, 'A needsCredentials warning is surfaced');
    }

    public function testUnknownFutureSchemaVersionAborts(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_future');
        $data = $this->service()->export($form);
        $data['_meta']['schemaVersion'] = FormPortabilityService::SCHEMA_VERSION + 99;

        $this->expectException(InvalidArgumentException::class);
        $this->service()->import($data, ['mode' => FormPortabilityService::MODE_RENAME]);
    }

    public function testMissingSchemaVersionAborts(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_noversion');
        $data = $this->service()->export($form);
        unset($data['_meta']['schemaVersion']);

        $this->expectException(InvalidArgumentException::class);
        $this->service()->import($data, ['mode' => FormPortabilityService::MODE_RENAME]);
    }

    public function testMissingSiteContentIsSkippedWithWarning(): void
    {
        $this->requireCraft();
        $form = $this->seedRichForm('port_missing_site');
        $data = $this->service()->export($form);

        // Inject content for a site handle that does not exist on this install.
        $data['form']['content']['nonexistent_site'] = [
            'title' => 'Ghost', 'description' => null, 'emailTo' => null,
            'emailSubject' => null, 'emailReplyTo' => null, 'emailBody' => null,
        ];

        $result = $this->service()->import($data, ['mode' => FormPortabilityService::MODE_RENAME]);

        $this->assertNotNull($result->form);
        $this->assertNotEmpty(array_filter(
            $result->warnings,
            static fn(string $w): bool => str_contains($w, 'nonexistent_site'),
        ));
    }
}

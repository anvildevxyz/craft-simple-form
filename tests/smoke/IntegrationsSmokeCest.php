<?php

namespace fabianhaef\simpleform\tests\smoke;

use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\DispatchStatus;
use fabianhaef\simpleform\integrations\IntegrationResult;
use fabianhaef\simpleform\integrations\IntegrationTypeInterface;
use fabianhaef\simpleform\Plugin;
use SmokeTester;

/**
 * Integration dispatch smoke tests (functional).
 *
 * Registers stub connectors and exercises the after-save dispatch path that
 * fires when a visitor submits through the shared {@see SubmissionService}.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class IntegrationsSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();
        $registry->registerType(SmokeStubOkIntegration::class);
        $registry->registerType(SmokeStubFailIntegration::class);
    }

    public function testSuccessfulIntegrationDispatchesOnSubmit(SmokeTester $I): void
    {
        $form = $this->createForm('Integration OK', 'intOk' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $integration = $this->createIntegration('stub_ok', 'Smoke OK');
        $this->attachIntegration((int) $form->id, (int) $integration->id);

        $result = $this->withSyncSideEffects(function () use ($form, $fieldId) {
            return $this->submitDirect($form, ['field_' . $fieldId => 'Ada']);
        });

        $I->assertNull($result['errors']);
        $I->assertInstanceOf(Submission::class, $result['submission']);

        $logs = $this->integrationLogs((int) $result['submission']->id);
        $I->assertCount(1, $logs);
        $I->assertSame(DispatchStatus::SUCCESS, $logs[0]['status']);
        $I->assertSame(200, (int) $logs[0]['responseCode']);
    }

    public function testFailedIntegrationLogsFailure(SmokeTester $I): void
    {
        $form = $this->createForm('Integration Fail', 'intFail' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $integration = $this->createIntegration('stub_fail', 'Smoke Fail');
        $this->attachIntegration((int) $form->id, (int) $integration->id);

        $result = $this->withSyncSideEffects(function () use ($form, $fieldId) {
            return $this->submitDirect($form, ['field_' . $fieldId => 'Ada']);
        });

        $I->assertInstanceOf(Submission::class, $result['submission']);

        $logs = $this->integrationLogs((int) $result['submission']->id);
        $I->assertCount(1, $logs);
        $I->assertSame(DispatchStatus::FAILED, $logs[0]['status']);
        $I->assertSame(503, (int) $logs[0]['responseCode']);
    }

    public function testDisabledIntegrationIsNotDispatched(SmokeTester $I): void
    {
        $form = $this->createForm('Integration Off', 'intOff' . uniqid());
        $this->createField((int) $form->id, 'text', 'name', 'Name');
        $integration = $this->createIntegration('stub_ok', 'Disabled', enabled: false);
        $this->attachIntegration((int) $form->id, (int) $integration->id);

        $submission = new Submission();
        $submission->formId = (int) $form->id;
        $submission->siteId = \Craft::$app->getSites()->getCurrentSite()->id;
        $submission->data = [];
        $submission->readStatus = 'new';
        \Craft::$app->getElements()->saveElement($submission);

        Plugin::getInstance()->getIntegrations()->dispatchForSubmission($submission);

        $count = (int) (new Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['submissionId' => $submission->id])
            ->count();
        $I->assertSame(0, $count);
    }

    public function testWebhookIntegrationCanBeSavedAndAttached(SmokeTester $I): void
    {
        $form = $this->createForm('Webhook', 'webhook' . uniqid());
        $hook = $this->createIntegration('webhook', 'Zapier', ['url' => 'https://example.test/hook']);
        $this->attachIntegration((int) $form->id, (int) $hook->id);

        $attached = Plugin::getInstance()->getIntegrations()->getEnabledIntegrationsForForm((int) $form->id);
        $I->assertCount(1, $attached);
        $I->assertSame('webhook', $attached[0]->type);
        $I->assertSame('https://example.test/hook', $attached[0]->settings['url']);
    }
}

/** Always-succeeds connector for smoke tests. */
class SmokeStubOkIntegration implements IntegrationTypeInterface
{
    public static function handle(): string
    {
        return 'stub_ok';
    }

    public static function displayName(): string
    {
        return 'Stub OK';
    }

    public function settingsHtml(array $settings): string
    {
        return '';
    }

    public function defineSettingsRules(): array
    {
        return [];
    }

    public function send(\fabianhaef\simpleform\elements\Submission $submission, array $settings): IntegrationResult
    {
        return IntegrationResult::success(200, 'ok');
    }
}

/** Always-fails connector for smoke tests. */
class SmokeStubFailIntegration implements IntegrationTypeInterface
{
    public static function handle(): string
    {
        return 'stub_fail';
    }

    public static function displayName(): string
    {
        return 'Stub Fail';
    }

    public function settingsHtml(array $settings): string
    {
        return '';
    }

    public function defineSettingsRules(): array
    {
        return [];
    }

    public function send(\fabianhaef\simpleform\elements\Submission $submission, array $settings): IntegrationResult
    {
        return IntegrationResult::failure(503, 'service down');
    }
}

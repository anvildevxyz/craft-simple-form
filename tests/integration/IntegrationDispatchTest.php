<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\DispatchStatus;
use fabianhaef\simpleform\integrations\IntegrationResult;
use fabianhaef\simpleform\integrations\IntegrationTypeInterface;
use fabianhaef\simpleform\jobs\SendIntegrationJob;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/** Always-succeeds connector. */
class StubOkIntegration implements IntegrationTypeInterface
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

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        return IntegrationResult::success(200, 'ok');
    }
}

/** Always-fails connector. */
class StubFailIntegration implements IntegrationTypeInterface
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

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        return IntegrationResult::failure(503, 'service down');
    }
}

/**
 * @group requires-craft
 */
class IntegrationDispatchTest extends SimpleFormTestCase
{
    protected function _before(): void
    {
        parent::_before();
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();
        $registry->registerType(StubOkIntegration::class);
        $registry->registerType(StubFailIntegration::class);
    }

    private function makeSubmission(int $formId): Submission
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = [];
        $sub->readStatus = 'new';
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        return $sub;
    }

    private function makeIntegration(int $formId, string $type, bool $enabled = true): IntegrationModel
    {
        $service = Plugin::getInstance()->getIntegrations();
        $m = new IntegrationModel();
        $m->type = $type;
        $m->name = ucfirst($type);
        $m->enabled = $enabled;
        $this->assertTrue($service->saveIntegration($m));
        // Attach the global definition to the form so it is in the form's
        // dispatch set.
        $service->toggleFormIntegration($formId, (int) $m->id);
        return $m;
    }

    /** @return array<int, array<string, mixed>> */
    private function logsFor(int $submissionId): array
    {
        return (new Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['submissionId' => $submissionId])
            ->all();
    }

    public function testEndToEndSyncDispatchOnSubmit(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->dispatchIntegrationsSynchronously = true;

        try {
            $form = $this->createForm('Dispatch E2E', 'dispatch_e2e');
            $fieldId = $this->createField($form->id, 'text', 'name', 'Name');
            $this->makeIntegration((int) $form->id, 'stub_ok');

            /** @var SubmissionService $service */
            $service = Plugin::getInstance()->getSubmissionService();
            $result = $service->submit($form, ['field_' . $fieldId => 'Ada'], ['skipCaptcha' => true]);

            $this->assertNotNull($result['submission'], 'submit should succeed');
            $logs = $this->logsFor((int) $result['submission']->id);
            $this->assertCount(1, $logs);
            $this->assertSame(DispatchStatus::SUCCESS, $logs[0]['status']);
            $this->assertSame(200, (int) $logs[0]['responseCode']);
        } finally {
            $settings->dispatchIntegrationsSynchronously = false;
        }
    }

    public function testDisabledIntegrationIsNotDispatched(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Dispatch Disabled', 'dispatch_disabled');
        $this->makeIntegration((int) $form->id, 'stub_ok', enabled: false);
        $sub = $this->makeSubmission((int) $form->id);

        Plugin::getInstance()->getIntegrations()->dispatchForSubmission($sub);

        $this->assertCount(0, $this->logsFor((int) $sub->id));
    }

    public function testUnknownTypeLogsFailure(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Dispatch Ghost', 'dispatch_ghost');
        $integration = $this->makeIntegration((int) $form->id, 'ghost_type');
        $sub = $this->makeSubmission((int) $form->id);

        $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $sub);

        $this->assertFalse($result->success);
        $logs = $this->logsFor((int) $sub->id);
        $this->assertCount(1, $logs);
        $this->assertSame(DispatchStatus::FAILED, $logs[0]['status']);
        $this->assertStringContainsString('Unknown integration type', (string) $logs[0]['message']);
    }

    public function testJobThrowsOnFailureToTriggerRetry(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Dispatch Fail', 'dispatch_fail');
        $integration = $this->makeIntegration((int) $form->id, 'stub_fail');
        $sub = $this->makeSubmission((int) $form->id);

        $job = new SendIntegrationJob([
            'integrationId' => $integration->id,
            'submissionId' => (int) $sub->id,
        ]);

        try {
            $job->execute(Craft::$app->getQueue());
            $this->fail('Expected a failed dispatch to throw so the queue retries');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failed', strtolower($e->getMessage()));
        }

        $logs = $this->logsFor((int) $sub->id);
        $this->assertCount(1, $logs);
        $this->assertSame(DispatchStatus::FAILED, $logs[0]['status']);
        $this->assertSame(503, (int) $logs[0]['responseCode']);
    }

    public function testJobNoopWhenIntegrationDeleted(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Dispatch Gone', 'dispatch_gone');
        $sub = $this->makeSubmission((int) $form->id);

        $job = new SendIntegrationJob([
            'integrationId' => 999999, // never existed
            'submissionId' => (int) $sub->id,
        ]);

        // Must not throw; nothing to dispatch.
        $job->execute(Craft::$app->getQueue());
        $this->assertCount(0, $this->logsFor((int) $sub->id));
    }

    public function testQueueModeEnqueuesRatherThanRunningInline(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->dispatchIntegrationsSynchronously = false; // default, explicit

        $form = $this->createForm('Dispatch Queue', 'dispatch_queue');
        $this->makeIntegration((int) $form->id, 'stub_ok');
        $sub = $this->makeSubmission((int) $form->id);

        Plugin::getInstance()->getIntegrations()->dispatchForSubmission($sub);

        // In queue mode nothing runs inline, so no log row is written during the
        // request — the work is deferred to the job.
        $this->assertCount(0, $this->logsFor((int) $sub->id));
    }
}

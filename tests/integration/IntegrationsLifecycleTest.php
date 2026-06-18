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

/**
 * A capturing connector standing in for a remote endpoint, so the lifecycle
 * smoke can assert "payload received" and simulate the endpoint breaking —
 * deterministically, with no network.
 */
class CapturingIntegration implements IntegrationTypeInterface
{
    /** @var list<array<string, mixed>> */
    public static array $received = [];
    public static bool $shouldFail = false;

    public static function reset(): void
    {
        self::$received = [];
        self::$shouldFail = false;
    }

    public static function handle(): string
    {
        return 'capturing';
    }

    public static function displayName(): string
    {
        return 'Capturing (test)';
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
        self::$received[] = [
            'submissionId' => (int) $submission->id,
            'data' => $submission->data,
        ];

        return self::$shouldFail
            ? IntegrationResult::failure(502, 'simulated outage')
            : IntegrationResult::success(200, 'ok');
    }
}

/**
 * The documented integrations lifecycle as one end-to-end smoke:
 * configure → submit → payload received → success logged → endpoint breaks →
 * failed logged → resend re-dispatches.
 *
 * @group requires-craft
 */
class IntegrationsLifecycleTest extends SimpleFormTestCase
{
    protected function _before(): void
    {
        parent::_before();
        CapturingIntegration::reset();
        if (class_exists(\Craft::class) && Craft::$app !== null) {
            Plugin::getInstance()->getIntegrationTypeRegistry()->registerType(CapturingIntegration::class);
            Plugin::getInstance()->getSettings()->dispatchIntegrationsSynchronously = true;
        }
    }

    protected function _after(): void
    {
        if (class_exists(\Craft::class) && Craft::$app !== null) {
            Plugin::getInstance()->getSettings()->dispatchIntegrationsSynchronously = false;
        }
        parent::_after();
    }

    /** @return array<int, array<string, mixed>> */
    private function logsFor(int $submissionId): array
    {
        return (new Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['submissionId' => $submissionId])
            ->orderBy(['id' => SORT_ASC])
            ->all();
    }

    public function testFullLifecycle(): void
    {
        $this->requireCraft();

        // Configure: a form with one field and an enabled integration.
        $form = $this->createForm('Lifecycle', 'lifecycle_form');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name');

        $integration = new IntegrationModel();
        $integration->type = 'capturing';
        $integration->name = 'Capturing';
        $integration->enabled = true;
        $integrations = Plugin::getInstance()->getIntegrations();
        $integrations->saveIntegration($integration);
        $integrations->toggleFormIntegration((int) $form->id, (int) $integration->id);

        // Submit: the connector receives the payload and a success log is written.
        $service = Plugin::getInstance()->getSubmissionService();
        $first = $service->submit($form, ['field_' . $fieldId => 'Ada'], ['skipCaptcha' => true]);
        $firstId = (int) $first['submission']->id;

        $this->assertCount(1, CapturingIntegration::$received, 'connector should receive the submission');
        $this->assertSame($firstId, CapturingIntegration::$received[0]['submissionId']);
        $logs = $this->logsFor($firstId);
        $this->assertCount(1, $logs);
        $this->assertSame(DispatchStatus::SUCCESS, $logs[0]['status']);

        // Endpoint breaks: a new submission now logs a failure.
        CapturingIntegration::$shouldFail = true;
        $second = $service->submit($form, ['field_' . $fieldId => 'Grace'], ['skipCaptcha' => true]);
        $secondId = (int) $second['submission']->id;

        $secondLogs = $this->logsFor($secondId);
        $this->assertCount(1, $secondLogs);
        $this->assertSame(DispatchStatus::FAILED, $secondLogs[0]['status']);
        $this->assertSame(502, (int) $secondLogs[0]['responseCode']);

        // Resend: running the job for the failed submission re-dispatches; it
        // throws (so the queue would retry) and records another failed attempt.
        $job = new SendIntegrationJob([
            'integrationId' => $integration->id,
            'submissionId' => $secondId,
        ]);
        try {
            $job->execute(Craft::$app->getQueue());
            $this->fail('A failed dispatch should throw to trigger a retry');
        } catch (\RuntimeException) {
            // expected
        }

        $afterResend = $this->logsFor($secondId);
        $this->assertCount(2, $afterResend, 'resend should record a second attempt');
        $this->assertSame(2, (int) $afterResend[1]['attempts']);

        // Recovery: endpoint healthy again, resend succeeds.
        CapturingIntegration::$shouldFail = false;
        (new SendIntegrationJob([
            'integrationId' => $integration->id,
            'submissionId' => $secondId,
        ]))->execute(Craft::$app->getQueue());

        $final = $this->logsFor($secondId);
        $this->assertSame(DispatchStatus::SUCCESS, end($final)['status']);
    }
}

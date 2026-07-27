<?php

namespace anvildev\simpleform\jobs;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use Craft;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

/**
 * Dispatches one outbound integration for one submission, off the queue. Pushed
 * by {@see \anvildev\simpleform\services\IntegrationsService::dispatchForSubmission()}
 * so a slow/failing third party never blocks the visitor's submit.
 *
 * A failed attempt throws so the queue retries (up to {@see canRetry()}); each
 * attempt records its own integration-log row.
 */
class SendIntegrationJob extends BaseJob implements RetryableJobInterface
{
    public ?int $integrationId = null;
    public ?int $submissionId = null;

    public function execute($queue): void
    {
        if ($this->integrationId === null || $this->submissionId === null) {
            return;
        }

        $service = Plugin::getInstance()->getIntegrations();

        // The config or submission may have been deleted between enqueue and run.
        $integration = $service->getIntegrationById($this->integrationId);
        if ($integration === null) {
            return;
        }
        // Worker runs in primary-site context: search across all sites so a
        // submission made on a non-primary site is still found (matches SendNotifications).
        $submission = Submission::find()->siteId('*')->id($this->submissionId)->one();
        if ($submission === null) {
            return;
        }

        $result = $service->runOnce($integration, $submission);
        if (!$result->success) {
            // Throw so the queue retries; the failed attempt (with the detailed,
            // secret-scrubbed message) is already logged. The exception that
            // surfaces in the queue UI stays generic (F16, CWE-209) so a remote
            // response body can't leak into it.
            throw new \RuntimeException(Craft::t('simple-form', 'Integration dispatch failed; see the integration log for details.'));
        }
    }

    public function getTtr(): int
    {
        return 300;
    }

    /**
     * @param int $attempt
     * @param \Throwable $error
     */
    public function canRetry($attempt, $error): bool
    {
        // Up to 3 attempts total (initial + 2 retries).
        return $attempt < 3;
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('simple-form', 'Dispatching form integration');
    }
}

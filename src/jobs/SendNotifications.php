<?php

namespace fabianhaef\simpleform\jobs;

use Craft;
use craft\queue\BaseJob;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;

/**
 * Sends a submission's notification emails off the queue (#143). Composing the
 * email can now render a submission PDF and read uploaded files for attachment —
 * potentially slow work that must never block the visitor's submit request. The
 * email body/recipients are resolved on the worker via the same
 * {@see \fabianhaef\simpleform\services\EmailService::sendSubmissionEmail()} path
 * the synchronous (Commerce/test) callers use.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class SendNotifications extends BaseJob
{
    // =========================================================================
    // PUBLIC PROPERTIES
    // =========================================================================

    public ?int $submissionId = null;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * @param \craft\queue\QueueInterface $queue
     */
    public function execute($queue): void
    {
        if ($this->submissionId === null) {
            return;
        }

        // Worker runs in primary-site context: search across all sites.
        $submission = Submission::find()->siteId('*')->id($this->submissionId)->one();
        if ($submission === null) {
            return;
        }

        $form = $submission->getForm();
        if ($form === null) {
            return;
        }

        Plugin::getInstance()->getEmailService()->sendSubmissionEmail(
            $form,
            $submission,
            is_array($submission->data) ? $submission->data : [],
        );
    }

    // =========================================================================
    // PROTECTED METHODS
    // =========================================================================

    protected function defaultDescription(): ?string
    {
        return Craft::t('simple-form', 'Sending form notifications');
    }
}

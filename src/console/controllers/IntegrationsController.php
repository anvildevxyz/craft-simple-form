<?php

namespace anvildev\simpleform\console\controllers;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\jobs\SendIntegrationJob;
use anvildev\simpleform\Plugin;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * `simple-form/integrations/*` — re-queue outbound integration dispatch (#106).
 */
class IntegrationsController extends Controller
{
    /** Submission id to re-dispatch (required). */
    public ?int $submission = null;
    /** Limit to a single integration id (default: all enabled on the form). */
    public ?int $integration = null;

    /**
     * @param string $actionID
     * @return list<string>
     */
    public function options($actionID): array
    {
        return $actionID === 'redispatch' ? ['submission', 'integration'] : [];
    }

    /**
     * Re-queue integration dispatch for a submission (all enabled, or one --integration).
     */
    public function actionRedispatch(): int
    {
        if ($this->submission === null) {
            $this->stderr("--submission=<id> is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $submission = Submission::find()->id($this->submission)->siteId('*')->one();
        if (!$submission instanceof Submission) {
            $this->stderr("No submission found with id {$this->submission}.\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        $service = Plugin::getInstance()->getIntegrations();

        if ($this->integration !== null) {
            if ($service->getIntegrationById($this->integration) === null) {
                $this->stderr("No integration found with id {$this->integration}.\n", Console::FG_RED);
                return ExitCode::DATAERR;
            }
            Craft::$app->getQueue()->push(new SendIntegrationJob([
                'integrationId' => $this->integration,
                'submissionId' => (int) $submission->id,
            ]));
            $this->stdout("Queued integration {$this->integration} for submission {$submission->id}.\n", Console::FG_GREEN);
            return ExitCode::OK;
        }

        $service->dispatchForSubmission($submission);
        $this->stdout("Queued all enabled integrations for submission {$submission->id}.\n", Console::FG_GREEN);

        return ExitCode::OK;
    }
}

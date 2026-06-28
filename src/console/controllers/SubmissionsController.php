<?php

namespace anvildev\simpleform\console\controllers;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SubmissionCsv;
use anvildev\simpleform\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * `simple-form/submissions/*` — bulk submission operations from the CLI (#106).
 */
class SubmissionsController extends Controller
{
    /** Age threshold in days for purge. */
    public ?int $days = null;
    /** Restrict to a single form by handle. */
    public ?string $form = null;
    /** Scrub data in place instead of deleting. */
    public bool $anonymize = false;
    /** Write export CSV to this path instead of stdout. */
    public ?string $out = null;

    /**
     * @param string $actionID
     * @return list<string>
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'purge' => ['days', 'form', 'anonymize'],
            'export' => ['form', 'out'],
            default => [],
        };
    }

    /**
     * Delete (or anonymize) submissions older than --days, optionally for one --form.
     */
    public function actionPurge(): int
    {
        if ($this->days === null || $this->days <= 0) {
            $this->stderr("--days=<n> is required (must be > 0).\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $formId = $this->resolveFormId();
        if ($formId === false) {
            return ExitCode::DATAERR;
        }

        $count = Plugin::getInstance()->getRetention()->purgeSubmissions($this->days, $this->anonymize, $formId);
        $verb = $this->anonymize ? 'Anonymized' : 'Deleted';
        $this->stdout("$verb $count submission(s) older than {$this->days} day(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Export submissions as CSV to stdout or --out=<path>, optionally for one --form.
     */
    public function actionExport(): int
    {
        $formId = $this->resolveFormId();
        if ($formId === false) {
            return ExitCode::DATAERR;
        }

        $query = Submission::find()->siteId('*');
        if ($formId !== null) {
            $query->formId($formId);
        }

        $csv = SubmissionCsv::fromSubmissions($query->all());

        if ($this->out !== null) {
            file_put_contents($this->out, $csv);
            $this->stdout("Wrote {$this->out}\n", Console::FG_GREEN);
        } else {
            $this->stdout($csv);
        }

        return ExitCode::OK;
    }

    /**
     * Cancel submissions whose payment has stayed pending past the configured
     * TTL (paymentPendingTtlMinutes) — abandoned offsite/redirect checkouts
     * (#116). Mirrors the automatic garbage-collection sweep, for ops/cron use.
     */
    public function actionExpirePayments(): int
    {
        $count = Plugin::getInstance()->getPayments()->expirePending();
        $this->stdout("Canceled {$count} expired pending payment(s).\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * @return int|null|false form id, null for "all forms", or false on a bad handle
     */
    private function resolveFormId(): int|null|false
    {
        if ($this->form === null) {
            return null;
        }

        $form = Form::find()->handle($this->form)->siteId('*')->one();
        if (!$form) {
            $this->stderr("No form found with handle \"{$this->form}\".\n", Console::FG_RED);
            return false;
        }

        return (int) $form->id;
    }
}

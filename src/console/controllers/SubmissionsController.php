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
    /** Email address to match for the GDPR export/erase commands (#314). */
    public ?string $email = null;
    /** Report the scope without mutating anything (erase-by-email preview). */
    public bool $dryRun = false;

    /**
     * @param string $actionID
     * @return list<string>
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'purge' => ['days', 'form', 'anonymize'],
            'export' => ['form', 'out'],
            'export-by-email' => ['email', 'out'],
            'erase-by-email' => ['email', 'anonymize', 'dryRun'],
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
     * GDPR subject-access: export every submission tied to --email as CSV to
     * stdout or --out=<path>. Matching scans the linked user's email and the
     * submitted JSON data (#314).
     */
    public function actionExportByEmail(): int
    {
        $ids = $this->resolveEmailMatches();
        if ($ids === false) {
            return ExitCode::USAGE;
        }

        $submissions = $ids === []
            ? []
            : Submission::find()->siteId('*')->id($ids)->all();

        $csv = SubmissionCsv::fromSubmissions($submissions);

        if ($this->out !== null) {
            file_put_contents($this->out, $csv);
            $this->stdout("Wrote " . count($submissions) . " submission(s) for {$this->email} to {$this->out}\n", Console::FG_GREEN);
        } else {
            $this->stdout($csv);
        }

        return ExitCode::OK;
    }

    /**
     * GDPR right-to-erasure: delete (or anonymize, per the
     * `anonymizeInsteadOfDelete` setting or --anonymize) every submission tied to
     * --email. Pass --dry-run to report the scope without mutating anything (#314).
     */
    public function actionEraseByEmail(): int
    {
        $ids = $this->resolveEmailMatches();
        if ($ids === false) {
            return ExitCode::USAGE;
        }

        if ($this->dryRun) {
            $this->stdout('Would erase ' . count($ids) . " submission(s) for {$this->email} (dry run, nothing changed).\n", Console::FG_YELLOW);
            return ExitCode::OK;
        }

        // --anonymize forces scrub-in-place; without it, honor the plugin setting.
        $count = Plugin::getInstance()->getRetention()->eraseSubmissions($ids, $this->anonymize ? true : null);
        $verb = ($this->anonymize || Plugin::getInstance()->getSettings()->anonymizeInsteadOfDelete) ? 'Anonymized' : 'Deleted';
        $this->stdout("$verb $count submission(s) for {$this->email}.\n", Console::FG_GREEN);

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
     * Resolve the submission ids matching --email via the shared retention query,
     * or false (with a usage error printed) when --email is missing. Returned by
     * both GDPR commands so they match identically (#314).
     *
     * @return list<int>|false
     */
    private function resolveEmailMatches(): array|false
    {
        $email = $this->email !== null ? trim($this->email) : '';
        if ($email === '') {
            $this->stderr("--email=<address> is required.\n", Console::FG_RED);
            return false;
        }

        return Plugin::getInstance()->getRetention()->findSubmissionIdsByEmail($email);
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

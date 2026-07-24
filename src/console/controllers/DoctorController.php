<?php

namespace anvildev\simpleform\console\controllers;

use anvildev\simpleform\Plugin;
use craft\console\Controller;
use craft\db\Query;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * `simple-form/doctor` — read-only configuration + data health check (#106).
 */
class DoctorController extends Controller
{
    public function actionIndex(): int
    {
        $settings = Plugin::getInstance()->getSettings();

        $this->stdout("Simple Form — health check\n\n", Console::FG_CYAN, Console::BOLD);

        $this->stdout("Data\n", Console::BOLD);
        $this->line('Forms', (string) (new Query())->from('{{%simpleform_forms}}')->count());
        $this->line('Submissions', (string) (new Query())->from('{{%simpleform_submissions}}')->count());
        $this->line('Integrations', (string) (new Query())->from('{{%simpleform_integrations}}')->count());

        $this->stdout("\nSpam protection\n", Console::BOLD);
        $this->line('Honeypot', $settings->enableHoneypot ? 'on' : 'off');
        if ($settings->enableCaptcha) {
            $hasKeys = $settings->getActiveSiteKey() && $settings->getParsedSecretKey();
            $this->line('Captcha', "{$settings->selectedCaptchaProvider}" . ($hasKeys ? '' : ' (MISSING KEYS)'), $hasKeys);
        } else {
            $this->line('Captcha', 'off');
        }
        if ($settings->enableAkismet) {
            $this->line('Akismet', $settings->akismetApiKey ? "on ({$settings->akismetMode})" : 'on (MISSING KEY)', (bool) $settings->akismetApiKey);
        } else {
            $this->line('Akismet', 'off');
        }

        $this->stdout("\nMCP\n", Console::BOLD);
        $this->line('Server', $settings->enableMcp ? 'enabled' : 'disabled');
        $this->line('Tokens', (string) count(Plugin::getInstance()->getMcpTokenManager()->allTokens()));

        $this->stdout("\nRetention\n", Console::BOLD);
        $this->line('Submissions', $settings->retainSubmissionsDays > 0 ? "{$settings->retainSubmissionsDays} days" . ($settings->anonymizeInsteadOfDelete ? ' (anonymize)' : '') : 'keep forever');
        $this->line('Integration logs', $settings->retainIntegrationLogsDays > 0 ? "{$settings->retainIntegrationLogsDays} days" : 'keep forever');

        $this->stdout("\n");
        return ExitCode::OK;
    }

    private function line(string $label, string $value, ?bool $ok = null): void
    {
        $this->stdout('  ' . str_pad($label, 20));
        $color = $ok === null ? Console::FG_GREY : ($ok ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout($value . "\n", $color);
    }
}

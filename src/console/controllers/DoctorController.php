<?php

namespace anvildev\simpleform\console\controllers;

use anvildev\simpleform\Plugin;
use Craft;
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

        $this->paymentsSection($settings);

        $this->stdout("\nMCP\n", Console::BOLD);
        $this->line('Server', $settings->enableMcp ? 'enabled' : 'disabled');
        $this->line('Tokens', (string) count(Plugin::getInstance()->getMcpTokenManager()->allTokens()));

        $this->stdout("\nRetention\n", Console::BOLD);
        $this->line('Submissions', $settings->retainSubmissionsDays > 0 ? "{$settings->retainSubmissionsDays} days" . ($settings->anonymizeInsteadOfDelete ? ' (anonymize)' : '') : 'keep forever');
        $this->line('Integration logs', $settings->retainIntegrationLogsDays > 0 ? "{$settings->retainIntegrationLogsDays} days" : 'keep forever');

        $this->stdout("\n");
        return ExitCode::OK;
    }

    /**
     * Payments depend on four things outside this plugin (Commerce, a gateway,
     * the Donation purchasable, and — optionally — a pinned gateway handle).
     * Any of them missing surfaces only as "Payments are not available right
     * now." at submit time, so report them where they can be seen beforehand.
     */
    private function paymentsSection(\anvildev\simpleform\models\Settings $settings): void
    {
        $paymentForms = (int) (new Query())
            ->from('{{%simpleform_fields}}')
            ->where(['type' => 'payment'])
            ->count('DISTINCT [[formId]]');

        $this->stdout("\nPayments\n", Console::BOLD);

        if (!class_exists(\craft\commerce\Plugin::class) || !Craft::$app->getPlugins()->isPluginEnabled('commerce')) {
            $this->line('Commerce', 'not installed', $paymentForms === 0 ? null : false);
            if ($paymentForms > 0) {
                $this->line('Payment forms', "{$paymentForms} — these collect nothing without Commerce", false);
            }
            return;
        }

        $this->line('Commerce', 'installed');
        $this->line('Payment forms', (string) $paymentForms);

        $commerce = \craft\commerce\Plugin::getInstance();
        $gateways = $commerce->getGateways();
        $pinned = (string) ($settings->paymentGatewayHandle ?? '');
        $resolved = $pinned !== '' ? $gateways->getGatewayByHandle($pinned) : null;

        if ($pinned !== '' && $resolved === null) {
            // The charge path falls back to the first enabled gateway rather than
            // failing, so a typo'd handle silently bills through the wrong one.
            $fallback = $gateways->getAllCustomerEnabledGateways()->first();
            $this->line('Gateway', "'{$pinned}' NOT FOUND — falling back to " . ($fallback?->name ?? 'none'), false);
        } elseif ($resolved !== null) {
            $this->line('Gateway', "{$resolved->name} (pinned)");
        } else {
            $first = $gateways->getAllCustomerEnabledGateways()->first();
            $this->line('Gateway', $first?->name ?? 'NONE ENABLED', $first !== null);
        }

        $donation = \craft\commerce\elements\Donation::find()->status(null)->one();
        $available = $donation !== null && $donation->availableForPurchase;
        $this->line(
            'Donation',
            $donation === null ? 'MISSING' : ($available ? 'available' : 'NOT available for purchase'),
            $available,
        );

        $ttl = $settings->paymentPendingTtlMinutes;
        $this->line('Pending TTL', $ttl > 0 ? "{$ttl} min" : 'expiry disabled');
        $this->line('Pending now', (string) (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['paymentStatus' => 'pending'])
            ->count());
    }

    private function line(string $label, string $value, ?bool $ok = null): void
    {
        $this->stdout('  ' . str_pad($label, 20));
        $color = $ok === null ? Console::FG_GREY : ($ok ? Console::FG_GREEN : Console::FG_RED);
        $this->stdout($value . "\n", $color);
    }
}

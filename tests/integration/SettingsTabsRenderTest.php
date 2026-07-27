<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\models\Settings;
use Craft;
use craft\web\View;

/**
 * Render-smoke the settings tab templates. The unit/parity gate doesn't render
 * Twig, so a bad macro or unbalanced tag (cf. #104's forms.numberField) only
 * surfaces here. Also pins #102's MCP-disabled gating.
 *
 * @group requires-craft
 */
class SettingsTabsRenderTest extends SimpleFormTestCase
{
    private function render(string $tab, Settings $settings, array $extra = []): string
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            return $view->renderTemplate('simple-form/settings/_tabs/' . $tab, array_merge([
                'settings' => $settings,
                'captchaProviders' => ['recaptcha' => 'Google reCAPTCHA'],
                'mcpTokens' => [],
                'mcpScopes' => [],
                'mcpScopeLabels' => [],
                'integrations' => [],
                'typeNames' => ['webhook' => 'Webhook'],
                'auditEntries' => [],
                'auditUserNames' => [],
                // Mirrors SettingsController::renderTab so the Pro-locked inputs
                // render the same way the real CP renders them.
                'proLockedFields' => \anvildev\simpleform\Editions::isStandard() ? [] : \anvildev\simpleform\Editions::STANDARD_CONFIG_SETTINGS,
            ], $extra));
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    public function testEveryTabRendersWithoutError(): void
    {
        $this->requireCraft();
        $settings = new Settings();

        foreach (['general', 'email', 'spam', 'privacy', 'integrations', 'audit', 'mcp'] as $tab) {
            $html = $this->render($tab, $settings);
            $this->assertNotSame('', trim($html), "Tab '$tab' rendered empty");
        }
    }

    public function testSpamTabHasBoundedNumberMinScore(): void
    {
        $this->requireCraft();
        $html = $this->render('spam', new Settings());

        // The min-score field must be a bounded number input, not plain text.
        $this->assertMatchesRegularExpression(
            '/name="recaptchaV3MinScore"[^>]*type="number"|type="number"[^>]*name="recaptchaV3MinScore"/',
            $html,
        );
        $this->assertStringContainsString('max="1"', $html);
    }

    public function testSpamTabRendersRateLimitAndGraphqlBypassControls(): void
    {
        $this->requireCraft();
        $html = $this->render('spam', new Settings());

        // Rate-limit number input + the GraphQL captcha-bypass toggle are present.
        $this->assertStringContainsString('name="submitRateLimitPerMinute"', $html);
        $this->assertStringContainsString('allowGraphqlCaptchaBypass', $html);
    }

    public function testSpamTabRendersDenylistControls(): void
    {
        $this->requireCraft();
        $html = $this->render('spam', new Settings());

        $this->assertStringContainsString('name="enableDenylists"', $html);
        $this->assertStringContainsString('name="denylistMode"', $html);
        $this->assertStringContainsString('name="blockedKeywords"', $html);
        $this->assertStringContainsString('name="blockedEmails"', $html);
        $this->assertStringContainsString('name="blockedIps"', $html);
    }

    public function testSoloShowsProUpsellButLeavesInputsOperable(): void
    {
        $this->requireCraft();

        $plugin = \anvildev\simpleform\Plugin::getInstance();
        $originalEdition = $plugin->edition;

        try {
            // Pro: no upsell notice on the Pro tabs.
            $plugin->edition = \anvildev\simpleform\Editions::STANDARD;
            $this->assertStringNotContainsString('Standard feature', $this->render('spam', new Settings()));
            $this->assertStringNotContainsString('Standard feature', $this->render('privacy', new Settings()));

            // Solo: the upsell notice appears. The off-switches stay operable (so a
            // downgraded site can still stop a running Standard feature), but the
            // companion config inputs render read-only (can't reconfigure it).
            $plugin->edition = \anvildev\simpleform\Editions::SOLO;
            $this->assertFalse(\anvildev\simpleform\Editions::isStandard(), 'edition should be Solo');

            $soloSpam = $this->render('spam', new Settings());
            $this->assertStringContainsString('Standard feature', $soloSpam);
            // Off-switches operable (can turn off)...
            $this->assertDoesNotMatchRegularExpression('/name="enableAkismet"[^>]*\bdisabled\b/', $soloSpam);
            $this->assertDoesNotMatchRegularExpression('/name="enableDenylists"[^>]*\bdisabled\b/', $soloSpam);
            // ...spam verdict modes operable (can de-escalate block -> flag)...
            $this->assertDoesNotMatchRegularExpression('/name="akismetMode"[^>]*\bdisabled\b/', $soloSpam);
            $this->assertDoesNotMatchRegularExpression('/name="denylistMode"[^>]*\bdisabled\b/', $soloSpam);

            $soloPrivacy = $this->render('privacy', new Settings());
            $this->assertStringContainsString('Standard feature', $soloPrivacy);
            // Retention day count (the off-switch) operable.
            $this->assertDoesNotMatchRegularExpression('/name="retainSubmissionsDays"[^>]*\bdisabled\b/', $soloPrivacy);

            // The anonymize lightswitch renders its frozen state as 'noteditable'.
            $this->assertStringContainsString('noteditable', $soloPrivacy);
        } finally {
            $plugin->edition = $originalEdition;
        }
    }

    public function testEveryFrozenProConfigFieldIsWiredReadOnly(): void
    {
        $this->requireCraft();

        // Source-level guardrail: every field frozen on save (STANDARD_CONFIG_SETTINGS)
        // must also be wired to `proLockedFields` in its settings template — else a
        // Solo operator could edit it and have the change silently dropped on save.
        $dir = __DIR__ . '/../../src/templates/settings/_tabs/';
        $tpl = file_get_contents($dir . 'spam.twig') . file_get_contents($dir . 'privacy.twig');

        foreach (\anvildev\simpleform\Editions::STANDARD_CONFIG_SETTINGS as $field) {
            $this->assertStringContainsString(
                "'$field' in proLockedFields",
                $tpl,
                "$field is frozen on save but not disabled via proLockedFields in its template",
            );
        }
    }

    public function testMcpTokenUiGatedOnEnableFlag(): void
    {
        $this->requireCraft();

        $disabled = $this->render('mcp', new Settings()); // enableMcp defaults false
        $this->assertStringContainsString('Token Management', $disabled);
        $this->assertStringContainsString('Enable the MCP server above', $disabled);
        $this->assertStringNotContainsString('Create a token', $disabled);

        $on = new Settings();
        $on->enableMcp = true;
        $enabled = $this->render('mcp', $on);
        $this->assertStringContainsString('Create a token', $enabled);
        $this->assertStringNotContainsString('Enable the MCP server above', $enabled);
    }
}

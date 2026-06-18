<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\web\View;
use fabianhaef\simpleform\models\Settings;

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
            ], $extra));
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    public function testEveryTabRendersWithoutError(): void
    {
        $this->requireCraft();
        $settings = new Settings();

        foreach (['general', 'email', 'spam', 'integrations', 'mcp'] as $tab) {
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

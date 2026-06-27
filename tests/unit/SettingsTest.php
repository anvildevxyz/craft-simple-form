<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\models\Settings;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the settings model defaults and the wiring that consumes them.
 *
 * These assertions stay at the source/reflection level (like the other unit
 * tests in this suite) so they run without a bootstrapped Craft application.
 */
class SettingsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function defaults(): array
    {
        return (new ReflectionClass(Settings::class))->getDefaultProperties();
    }

    private function source(string $relativePath): string
    {
        $code = file_get_contents(__DIR__ . '/../../src/' . $relativePath);
        $this->assertNotFalse($code);
        return $code;
    }

    public function testDefaults(): void
    {
        $defaults = $this->defaults();

        $this->assertTrue($defaults['enableHoneypot'], 'Honeypot should be on by default');
        $this->assertFalse($defaults['enableCaptcha'], 'Captcha should be off by default');
        $this->assertSame('recaptcha-v3', $defaults['captchaType']);
        $this->assertSame('recaptcha', $defaults['selectedCaptchaProvider'], 'reCAPTCHA stays the default provider (backward compatible)');
        $this->assertSame('database', $defaults['storageLocation']);
        $this->assertSame(0.5, $defaults['recaptchaV3MinScore']);
        $this->assertNotEmpty($defaults['submitMessage']);
        $this->assertNotEmpty($defaults['errorMessage']);
        $this->assertFalse($defaults['applyFormsConfigOnUp'], 'Auto-apply on `up` is opt-in (off by default)');
    }

    public function testSecretsAreEnvParseable(): void
    {
        $code = $this->source('models/Settings.php');

        // Secret/key attributes must be registered with the env parser so they
        // can reference environment variables instead of plaintext.
        $this->assertStringContainsString('EnvAttributeParserBehavior', $code);
        foreach (['recaptchaV3SecretKey', 'recaptchaV2SecretKey'] as $attr) {
            $this->assertStringContainsString("'$attr'", $code);
        }
        // Consumers resolve secrets through App::parseEnv.
        $this->assertStringContainsString('App::parseEnv', $code);
    }

    public function testControllerSavesUnderPluginSettings(): void
    {
        $code = $this->source('controllers/SettingsController.php');

        // Regression guard: the original bug read/wrote the plugin's install
        // record (`plugins.simple-form`) instead of its settings. Settings must
        // go through Craft's plugin-settings save path.
        $this->assertStringContainsString('savePluginSettings', $code);
        $this->assertStringNotContainsString("set('plugins.simple-form'", $code);
        $this->assertStringNotContainsString("get('plugins.simple-form')", $code);
    }

    public function testPluginRegistersSettingsModel(): void
    {
        $code = $this->source('Plugin.php');

        $this->assertStringContainsString('createSettingsModel', $code);
        $this->assertStringContainsString('return new Settings();', $code);
        $this->assertStringContainsString("'captchaService' => CaptchaService::class", $code);
    }

    public function testEmailServiceUsesConfiguredSender(): void
    {
        $code = $this->source('services/EmailService.php');

        $this->assertStringContainsString('getSenderEmail', $code);
        // The Craft 4-only system-settings API must no longer be used.
        $this->assertStringNotContainsString('getSystemSettings', $code);
    }

    public function testSubmitControllerRoutesThroughSubmissionService(): void
    {
        // The controller MUST route through createFromRequest() — the upload-aware
        // entry point that resolves file uploads (→ assets), honeypot, and userId
        // before delegating to submit(). Calling submit() directly with body-only
        // values silently drops file uploads (regression guarded here).
        $code = $this->source('controllers/SubmitController.php');

        // The service may be held in a local before createFromRequest() is called.
        $this->assertStringContainsString('getSubmissionService()', $code);
        $this->assertStringContainsString('->createFromRequest(', $code);
        $this->assertStringContainsString('submitMessage', $code);
    }

    public function testSubmissionServiceGatesHoneypotAndVerifiesCaptcha(): void
    {
        $code = $this->source('services/SubmissionService.php');

        $this->assertStringContainsString('enableHoneypot', $code);
        $this->assertStringContainsString('getCaptchaService()->verify(', $code);
    }

    public function testFormRenderServiceGatesHoneypotAndRendersCaptcha(): void
    {
        // The render path moved out of TwigExtension into FormRenderService (#137);
        // the Twig extension is now a thin delegator. Honeypot gating + captcha
        // delegation live in the service.
        $code = $this->source('services/FormRenderService.php');

        $this->assertStringContainsString('enableHoneypot', $code);
        $this->assertStringContainsString('_captcha', $code);
        // The widget markup lives in the provider; the service delegates.
        $this->assertStringContainsString('getCaptchaService()->renderWidget()', $code);
    }

    public function testRecaptchaProviderRendersWidgetMarkup(): void
    {
        $code = $this->source('captcha/RecaptchaProvider.php');
        $this->assertStringContainsString('g-recaptcha', $code);
    }
}

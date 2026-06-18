<?php

namespace fabianhaef\simpleform\tests\integration;

use fabianhaef\simpleform\captcha\RecaptchaProvider;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\Plugin;

/**
 * #85 — captcha provider abstraction. Verifies the default delegates to
 * reCAPTCHA (backward-compatible) and that the widget renders through the
 * provider.
 *
 * @group requires-craft
 */
class CaptchaProviderTest extends SimpleFormTestCase
{
    public function testDefaultProviderIsRecaptcha(): void
    {
        $this->requireCraft();
        $this->assertInstanceOf(
            RecaptchaProvider::class,
            Plugin::getInstance()->getCaptchaService()->provider(),
        );
    }

    public function testVerifyReturnsTrueWhenCaptchaDisabled(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->enableCaptcha = false;
        $this->assertTrue(Plugin::getInstance()->getCaptchaService()->verify('anything'));
    }

    public function testRenderWidgetEmptyWhenDisabled(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->enableCaptcha = false;
        $this->assertSame('', Plugin::getInstance()->getCaptchaService()->renderWidget());
    }

    public function testRecaptchaV2WidgetRendersThroughProvider(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();

        try {
            $settings->enableCaptcha = true;
            $settings->selectedCaptchaProvider = 'recaptcha';
            $settings->captchaType = Settings::CAPTCHA_V2;
            $settings->recaptchaV2SiteKey = 'test-site-key';

            $html = Plugin::getInstance()->getCaptchaService()->renderWidget();
            $this->assertStringContainsString('g-recaptcha', $html);
            $this->assertStringContainsString('test-site-key', $html);
        } finally {
            $settings->setAttributes($original, false);
        }
    }
}

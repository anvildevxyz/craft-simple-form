<?php

namespace fabianhaef\simpleform\tests\integration;

use fabianhaef\simpleform\captcha\HcaptchaProvider;
use fabianhaef\simpleform\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/** hCaptcha provider with a mocked siteverify client. */
class MockHcaptcha extends HcaptchaProvider
{
    public function __construct(private MockHandler $mock)
    {
    }

    protected function httpClient(): Client
    {
        return new Client(['handler' => HandlerStack::create($this->mock)]);
    }
}

/**
 * #87 — hCaptcha. Provider selection, widget rendering, server verification.
 *
 * @group requires-craft
 */
class HcaptchaVerifyTest extends SimpleFormTestCase
{
    public function testServiceResolvesHcaptchaWhenSelected(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->selectedCaptchaProvider = 'hcaptcha';
            $this->assertInstanceOf(
                HcaptchaProvider::class,
                Plugin::getInstance()->getCaptchaService()->provider(),
            );
        } finally {
            $settings->setAttributes($original, false);
        }
    }

    public function testWidgetRendersWithSiteKey(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->hcaptchaSiteKey = 'hc-site-key';

        $html = (new HcaptchaProvider())->renderWidget($settings);
        $this->assertStringContainsString('h-captcha', $html);
        $this->assertStringContainsString('hc-site-key', $html);
        $this->assertStringContainsString('js.hcaptcha.com', $html);
    }

    public function testVerifySucceedsAndFails(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->hcaptchaSecretKey = 'hc-secret';

        $ok = new MockHcaptcha(new MockHandler([new Response(200, [], '{"success":true}')]));
        $this->assertTrue($ok->verify('token-abc', $settings));

        $bad = new MockHcaptcha(new MockHandler([new Response(200, [], '{"success":false}')]));
        $this->assertFalse($bad->verify('token-abc', $settings));
    }

    public function testVerifyFailsWithoutSecret(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->hcaptchaSecretKey = null;

        $provider = new MockHcaptcha(new MockHandler([]));
        $this->assertFalse($provider->verify('token-abc', $settings));
    }
}

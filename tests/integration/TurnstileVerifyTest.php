<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\captcha\TurnstileProvider;
use anvildev\simpleform\Plugin;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/** Turnstile provider with a mocked siteverify client. */
class MockTurnstile extends TurnstileProvider
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
 * #86 — Cloudflare Turnstile. Verifies provider selection, widget rendering,
 * and server verification (mocked siteverify).
 *
 * @group requires-craft
 */
class TurnstileVerifyTest extends SimpleFormTestCase
{
    public function testServiceResolvesTurnstileWhenSelected(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->selectedCaptchaProvider = 'turnstile';
            $this->assertInstanceOf(
                TurnstileProvider::class,
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
        $settings->turnstileSiteKey = 'ts-site-key';

        $html = (new TurnstileProvider())->renderWidget($settings);
        $this->assertStringContainsString('cf-turnstile', $html);
        $this->assertStringContainsString('ts-site-key', $html);
        $this->assertStringContainsString('challenges.cloudflare.com', $html);
    }

    public function testVerifySucceedsOnCloudflareSuccess(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->turnstileSecretKey = 'ts-secret';

        $provider = new MockTurnstile(new MockHandler([new Response(200, [], '{"success":true}')]));
        $this->assertTrue($provider->verify('token-abc', $settings));
    }

    public function testVerifyFailsOnCloudflareFailure(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->turnstileSecretKey = 'ts-secret';

        $provider = new MockTurnstile(new MockHandler([new Response(200, [], '{"success":false}')]));
        $this->assertFalse($provider->verify('token-abc', $settings));
    }

    public function testVerifyFailsWithoutSecret(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $settings->turnstileSecretKey = null;

        $provider = new MockTurnstile(new MockHandler([]));
        $this->assertFalse($provider->verify('token-abc', $settings));
    }
}

<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\captcha\TurnstileProvider;
use anvildev\simpleform\services\CaptchaProviderRegistry;
use PHPUnit\Framework\TestCase;

class TurnstileProviderTest extends TestCase
{
    public function testProviderContract(): void
    {
        $provider = new TurnstileProvider();
        $this->assertSame('turnstile', TurnstileProvider::handle());
        $this->assertSame('Cloudflare Turnstile', TurnstileProvider::displayName());
        $this->assertSame('cf-turnstile-response', $provider->tokenParam());
    }

    public function testRegisteredInRegistry(): void
    {
        $registry = new CaptchaProviderRegistry();
        $this->assertInstanceOf(TurnstileProvider::class, $registry->getProvider('turnstile'));
        $this->assertArrayHasKey('turnstile', $registry->all());
        $this->assertSame('Cloudflare Turnstile', $registry->all()['turnstile']);
    }
}

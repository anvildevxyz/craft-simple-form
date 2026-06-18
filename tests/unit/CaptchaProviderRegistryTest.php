<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\captcha\RecaptchaProvider;
use fabianhaef\simpleform\services\CaptchaProviderRegistry;
use PHPUnit\Framework\TestCase;

class NotACaptchaProvider
{
}

class CaptchaProviderRegistryTest extends TestCase
{
    public function testRecaptchaIsRegisteredByDefault(): void
    {
        $registry = new CaptchaProviderRegistry();
        $this->assertInstanceOf(RecaptchaProvider::class, $registry->getProvider('recaptcha'));
        $this->assertSame(['recaptcha' => 'Google reCAPTCHA'], $registry->all());
    }

    public function testResolveFallsBackToRecaptchaForUnknownOrEmpty(): void
    {
        $registry = new CaptchaProviderRegistry();
        $this->assertInstanceOf(RecaptchaProvider::class, $registry->resolve('nonexistent'));
        $this->assertInstanceOf(RecaptchaProvider::class, $registry->resolve(''));
        $this->assertInstanceOf(RecaptchaProvider::class, $registry->resolve('recaptcha'));
    }

    public function testRegisterNonProviderThrows(): void
    {
        $registry = new CaptchaProviderRegistry();
        $this->expectException(\InvalidArgumentException::class);
        /** @phpstan-ignore-next-line intentionally wrong type for the guard test */
        $registry->registerProvider(NotACaptchaProvider::class);
    }

    public function testRecaptchaProviderContract(): void
    {
        $provider = new RecaptchaProvider();
        $this->assertSame('recaptcha', RecaptchaProvider::handle());
        $this->assertSame('Google reCAPTCHA', RecaptchaProvider::displayName());
        $this->assertSame('g-recaptcha-response', $provider->tokenParam());
    }
}

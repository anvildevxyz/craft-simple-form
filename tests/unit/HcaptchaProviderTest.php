<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\captcha\HcaptchaProvider;
use fabianhaef\simpleform\services\CaptchaProviderRegistry;
use PHPUnit\Framework\TestCase;

class HcaptchaProviderTest extends TestCase
{
    public function testProviderContract(): void
    {
        $provider = new HcaptchaProvider();
        $this->assertSame('hcaptcha', HcaptchaProvider::handle());
        $this->assertSame('hCaptcha', HcaptchaProvider::displayName());
        $this->assertSame('h-captcha-response', $provider->tokenParam());
    }

    public function testRegisteredInRegistry(): void
    {
        $registry = new CaptchaProviderRegistry();
        $this->assertInstanceOf(HcaptchaProvider::class, $registry->getProvider('hcaptcha'));
        $this->assertSame('hCaptcha', $registry->all()['hcaptcha']);
    }
}

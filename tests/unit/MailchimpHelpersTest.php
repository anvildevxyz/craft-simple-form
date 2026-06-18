<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\integrations\MailchimpIntegration;
use PHPUnit\Framework\TestCase;

class MailchimpHelpersTest extends TestCase
{
    public function testDatacenterFromApiKey(): void
    {
        $this->assertSame('us5', MailchimpIntegration::datacenterFromApiKey('abcdef0123456789-us5'));
        $this->assertSame('us21', MailchimpIntegration::datacenterFromApiKey('key-us21'));
    }

    public function testDatacenterFromMalformedKeyIsNull(): void
    {
        $this->assertNull(MailchimpIntegration::datacenterFromApiKey('no-datacenter-suffix-'));
        $this->assertNull(MailchimpIntegration::datacenterFromApiKey('nodash'));
    }

    public function testSubscriberHashIsMd5OfLowercasedEmail(): void
    {
        $this->assertSame(md5('foo@bar.com'), MailchimpIntegration::subscriberHash('Foo@Bar.COM'));
    }
}

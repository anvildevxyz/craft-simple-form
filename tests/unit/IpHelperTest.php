<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\helpers\IpHelper;
use PHPUnit\Framework\TestCase;

/**
 * #315 — IP anonymization masks the host-identifying bits at capture time:
 * the last octet for IPv4, the low 80 bits for IPv6.
 */
class IpHelperTest extends TestCase
{
    public function testIpv4ZeroesTheLastOctet(): void
    {
        $this->assertSame('203.0.113.0', IpHelper::anonymize('203.0.113.42'));
        $this->assertSame('192.168.1.0', IpHelper::anonymize('192.168.1.255'));
    }

    public function testIpv4AlreadyMaskedIsStable(): void
    {
        $this->assertSame('203.0.113.0', IpHelper::anonymize('203.0.113.0'));
    }

    public function testIpv6ZeroesTheLow80Bits(): void
    {
        $this->assertSame('2001:db8:1::', IpHelper::anonymize('2001:db8:1:2:3:4:5:6'));
        $this->assertSame('2001:db8:abcd::', IpHelper::anonymize('2001:db8:abcd:ffff:ffff:ffff:ffff:ffff'));
    }

    public function testInvalidInputIsReturnedUnchanged(): void
    {
        $this->assertSame('', IpHelper::anonymize(''));
        $this->assertSame('not-an-ip', IpHelper::anonymize('not-an-ip'));
    }
}

<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\services\DenylistService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Pure-matching coverage for the denylist filters (#140). These exercise the
 * keyword/email/IP/CIDR matchers directly (no Craft boot needed); the full
 * settings-driven {@see DenylistService::match()} fork is covered by the
 * integration suite.
 */
class DenylistServiceTest extends TestCase
{
    private function call(string $method, mixed ...$args): mixed
    {
        $ref = new ReflectionMethod(DenylistService::class, $method);
        return $ref->invoke(new DenylistService(), ...$args);
    }

    /**
     * @return array<string, mixed>
     */
    private function field(string $type, mixed $value): array
    {
        return ['label' => 'x', 'type' => $type, 'value' => $value];
    }

    // Keywords
    // -------------------------------------------------------------------------

    public function testKeywordSubstringMatchesCaseInsensitively(): void
    {
        $data = ['field_1' => $this->field('text', 'Win at the CASINO tonight')];
        $this->assertSame('casino', $this->call('matchKeywords', "casino\ncrypto", $data));
    }

    public function testKeywordWildcardMatches(): void
    {
        $data = ['field_1' => $this->field('text', 'visit buy-cheap-pills online')];
        // A trailing '*' wildcard: the reported reason strips the edge wildcard.
        $this->assertSame('buy-cheap', $this->call('matchKeywords', 'buy-cheap*', $data));
    }

    public function testKeywordNoMatchReturnsNull(): void
    {
        $data = ['field_1' => $this->field('text', 'a perfectly normal message')];
        $this->assertNull($this->call('matchKeywords', "casino\ncrypto", $data));
    }

    public function testEmptyKeywordListIsNoop(): void
    {
        $data = ['field_1' => $this->field('text', 'casino')];
        $this->assertNull($this->call('matchKeywords', null, $data));
        $this->assertNull($this->call('matchKeywords', '   ', $data));
    }

    // Emails
    // -------------------------------------------------------------------------

    public function testEmailExactMatch(): void
    {
        $data = ['field_1' => $this->field('email', 'Bob@Example.com')];
        $this->assertSame('Bob@Example.com', $this->call('matchEmails', 'bob@example.com', $data));
    }

    public function testEmailDomainMatch(): void
    {
        $data = ['field_1' => $this->field('email', 'anyone@mailinator.com')];
        $this->assertSame('anyone@mailinator.com', $this->call('matchEmails', '@mailinator.com', $data));
    }

    public function testEmailWildcardSubdomainMatch(): void
    {
        $data = ['field_1' => $this->field('email', 'x@mail.spam.tld')];
        $this->assertSame('x@mail.spam.tld', $this->call('matchEmails', '*.spam.tld', $data));
    }

    public function testEmailNoMatchReturnsNull(): void
    {
        $data = ['field_1' => $this->field('email', 'bob@gmail.com')];
        $this->assertNull($this->call('matchEmails', "@mailinator.com\nbad@x.tld", $data));
    }

    // IPs
    // -------------------------------------------------------------------------

    public function testSingleIpMatch(): void
    {
        $this->assertTrue($this->call('matchIp', '203.0.113.5', '203.0.113.5'));
        $this->assertFalse($this->call('matchIp', '203.0.113.5', '203.0.113.6'));
    }

    public function testCidrV4InAndOutOfRange(): void
    {
        $this->assertTrue($this->call('ipInCidr', '203.0.113.42', '203.0.113.0/24'));
        $this->assertFalse($this->call('ipInCidr', '203.0.114.1', '203.0.113.0/24'));
    }

    public function testCidrV6InAndOutOfRange(): void
    {
        $this->assertTrue($this->call('ipInCidr', '2001:db8::1', '2001:db8::/32'));
        $this->assertFalse($this->call('ipInCidr', '2001:db9::1', '2001:db8::/32'));
    }

    public function testIsValidIpEntry(): void
    {
        $this->assertTrue(DenylistService::isValidIpEntry('203.0.113.5'));
        $this->assertTrue(DenylistService::isValidIpEntry('203.0.113.0/24'));
        $this->assertTrue(DenylistService::isValidIpEntry('2001:db8::/32'));
        $this->assertFalse(DenylistService::isValidIpEntry('999.0.0.1'));
        $this->assertFalse(DenylistService::isValidIpEntry('203.0.113.0/99'));
        $this->assertFalse(DenylistService::isValidIpEntry('not-an-ip'));
        $this->assertFalse(DenylistService::isValidIpEntry(''));
    }
}

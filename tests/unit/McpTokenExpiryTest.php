<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\mcp\McpToken;
use PHPUnit\Framework\TestCase;

/**
 * MCP token expiry (security review L2): a token may carry an optional expiry,
 * enforced fail-closed.
 */
class McpTokenExpiryTest extends TestCase
{
    private function token(?string $expiresAt): McpToken
    {
        return new McpToken(
            id: 'id',
            label: 'test',
            hash: 'hash',
            scopes: ['forms:read'],
            expiresAt: $expiresAt,
        );
    }

    public function testNullExpiryNeverExpires(): void
    {
        $this->assertFalse($this->token(null)->isExpired());
    }

    public function testFutureExpiryIsNotExpired(): void
    {
        $future = (new \DateTimeImmutable('+1 day'))->format(\DateTime::ATOM);
        $this->assertFalse($this->token($future)->isExpired());
    }

    public function testPastExpiryIsExpired(): void
    {
        $past = (new \DateTimeImmutable('-1 second'))->format(\DateTime::ATOM);
        $this->assertTrue($this->token($past)->isExpired());
    }

    public function testUnparseableExpiryFailsClosed(): void
    {
        $this->assertTrue($this->token('not-a-date')->isExpired());
    }

    public function testRoundTripPreservesExpiry(): void
    {
        $at = (new \DateTimeImmutable('+30 days'))->format(\DateTime::ATOM);
        $restored = McpToken::fromArray($this->token($at)->toArray());
        $this->assertSame($at, $restored->expiresAt);
    }

    public function testLegacyArrayWithoutExpiryIsNeverExpiring(): void
    {
        $restored = McpToken::fromArray([
            'id' => 'x',
            'label' => 'legacy',
            'hash' => 'h',
            'scopes' => ['forms:read'],
        ]);
        $this->assertNull($restored->expiresAt);
        $this->assertFalse($restored->isExpired());
    }
}

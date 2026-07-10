<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\helpers\AddressList;
use PHPUnit\Framework\TestCase;

/**
 * Shared CC/BCC address-list splitter (#313, cleanup for #324): the single
 * source of the separator regex that {@see \anvildev\simpleform\models\NotificationModel::validateAddressList}
 * and {@see \anvildev\simpleform\services\EmailService} both build on.
 */
class AddressListTest extends TestCase
{
    public function testNullReturnsEmpty(): void
    {
        $this->assertSame([], AddressList::split(null));
    }

    public function testBlankReturnsEmpty(): void
    {
        $this->assertSame([], AddressList::split('   '));
    }

    public function testSplitsOnCommaSemicolonAndWhitespace(): void
    {
        $this->assertSame(
            ['a@example.com', 'b@example.com', 'c@example.com'],
            AddressList::split("a@example.com, b@example.com;\tc@example.com"),
        );
    }

    public function testDoesNotDeduplicateOrValidate(): void
    {
        // Splitting is deliberately dumb — dedup and validation are the
        // caller's responsibility (NotificationModel validates, EmailService
        // filters + dedups).
        $this->assertSame(
            ['a@example.com', 'a@example.com', 'not-an-email'],
            AddressList::split('a@example.com, a@example.com, not-an-email'),
        );
    }
}

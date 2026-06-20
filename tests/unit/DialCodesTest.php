<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\helpers\DialCodes;
use PHPUnit\Framework\TestCase;

/**
 * Source-level unit coverage for the Phone field's curated dial-code list
 * (#123). The label() lookup is translated via Craft::t and is exercised in the
 * integration suite; the structural lookups here are Craft-free.
 */
class DialCodesTest extends TestCase
{
    public function testKnownAndDial(): void
    {
        $this->assertTrue(DialCodes::isKnown('CH'));
        $this->assertTrue(DialCodes::isKnown('ch'));
        $this->assertFalse(DialCodes::isKnown('ZZ'));

        $this->assertSame('+41', DialCodes::dial('CH'));
        $this->assertSame('+49', DialCodes::dial('de'));
        $this->assertNull(DialCodes::dial('ZZ'));
    }

    public function testAllowedNarrowsAndOrders(): void
    {
        $allowed = DialCodes::allowed(['de', 'CH']);

        $this->assertSame(['DE', 'CH'], array_keys($allowed));
        $this->assertSame('+49', $allowed['DE']['dial']);
    }

    public function testAllowedSkipsUnknownCodes(): void
    {
        $allowed = DialCodes::allowed(['CH', 'ZZ', '']);

        $this->assertSame(['CH'], array_keys($allowed));
    }

    public function testEmptyAllowlistReturnsFullList(): void
    {
        $this->assertSame(DialCodes::all(), DialCodes::allowed([]));
        $this->assertNotEmpty(DialCodes::all());
    }
}

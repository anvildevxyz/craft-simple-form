<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\fields\SignatureFieldType;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit coverage for the Signature field type (#129): the Craft-free seams
 * (type/label). Validation messages and rendered markup call Craft::t, so they
 * are exercised in the integration suite ({@see SignatureFieldTest}) where a
 * real Craft with the translation catalogs is booted.
 */
class SignatureFieldTypeTest extends TestCase
{
    public function testTypeAndLabel(): void
    {
        $this->assertSame('signature', SignatureFieldType::getType());
        $this->assertSame('Signature', SignatureFieldType::getLabel());
    }
}

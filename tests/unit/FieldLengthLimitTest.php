<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\fields\FieldType;
use anvildev\simpleform\fields\TextareaFieldType;
use anvildev\simpleform\fields\TextFieldType;
use PHPUnit\Framework\TestCase;

/**
 * The absolute server-side value ceiling in {@see FieldType::validate()} — an
 * oversized string is rejected even when the field defines no maxLength, so a
 * scripted POST can't inflate the submission `data` column with an unbounded
 * blob (CWE-770). Pure: {@see FieldType::t()} falls back to placeholder
 * interpolation when no Craft app is booted.
 */
class FieldLengthLimitTest extends TestCase
{
    public function testTextValueOverTheCeilingIsRejected(): void
    {
        // No maxLength configured — only the absolute ceiling applies.
        $field = new TextFieldType([]);
        $errors = $field->validate(str_repeat('a', 65536));

        $this->assertNotSame([], $errors, 'A >64 KB value must be rejected');
    }

    public function testTextValueUnderTheCeilingIsAccepted(): void
    {
        $field = new TextFieldType([]);

        $this->assertSame([], $field->validate(str_repeat('a', 1024)));
    }

    public function testTextareaSharesTheCeiling(): void
    {
        $this->assertNotSame([], (new TextareaFieldType([]))->validate(str_repeat('x', 70000)));
        $this->assertSame([], (new TextareaFieldType([]))->validate('a reasonable message'));
    }

    public function testDefaultCeilingIs64Kb(): void
    {
        $method = new \ReflectionMethod(FieldType::class, 'maxValueLength');
        $method->setAccessible(true);

        $this->assertSame(65535, $method->invoke(new TextFieldType([])));
    }

    public function testSignatureIsStructurallyExemptFromTheGenericCeiling(): void
    {
        // The Signature type enforces its own 5 MB size limit and must not chain
        // to FieldType::validate(), so a large-but-valid base64 PNG data URL is
        // never rejected by the generic 64 KB ceiling. Asserted structurally
        // (the type's validate() runs Craft::t and so isn't exercised in the
        // pure suite).
        $source = (string) file_get_contents(__DIR__ . '/../../src/fields/SignatureFieldType.php');

        $this->assertStringNotContainsString('parent::validate', $source);
    }
}

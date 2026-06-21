<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\fields\PhoneFieldType;
use PHPUnit\Framework\TestCase;

/**
 * Source-level unit coverage for the Phone field type (#123): type/label,
 * normalization, config accessors, the no-selector render, and export shape.
 *
 * Anything that goes through `Craft::t` (the validation messages and the
 * country-selector render, whose option labels are translated) is exercised in
 * the integration suite, which boots a real Craft with the catalogs loaded —
 * the same split the other field types follow.
 */
class PhoneFieldTypeTest extends TestCase
{
    public function testTypeAndLabel(): void
    {
        $this->assertSame('phone', PhoneFieldType::getType());
        $this->assertSame('Phone', PhoneFieldType::getLabel());
    }

    public function testNormalizeSwissNationalNumber(): void
    {
        $field = new PhoneFieldType(['defaultCountry' => 'CH']);
        $result = $field->normalize(['country' => 'CH', 'number' => '079 123 45 67']);

        $this->assertNotNull($result);
        $this->assertSame('+41791234567', $result['e164']);
        $this->assertSame('CH', $result['country']);
        $this->assertSame('079 123 45 67', $result['raw']);
    }

    public function testNormalizeStripsFormattingAndTelScheme(): void
    {
        $field = new PhoneFieldType(['defaultCountry' => 'CH']);
        $result = $field->normalize(['country' => 'CH', 'number' => 'tel: (079) 123-45.67']);

        $this->assertNotNull($result);
        $this->assertSame('+41791234567', $result['e164']);
    }

    public function testNormalizeKeepsInternationalPlusNumber(): void
    {
        $field = new PhoneFieldType(['defaultCountry' => 'CH']);
        $result = $field->normalize(['country' => 'CH', 'number' => '+49 30 1234567']);

        $this->assertNotNull($result);
        $this->assertSame('+49301234567', $result['e164']);
    }

    public function testNormalizeDropsSingleLeadingZeroForNational(): void
    {
        $field = new PhoneFieldType(['defaultCountry' => 'DE']);
        $result = $field->normalize(['country' => 'DE', 'number' => '030 1234567']);

        $this->assertNotNull($result);
        $this->assertSame('+49301234567', $result['e164']);
    }

    public function testNormalizeFlatStringUsesDefaultCountry(): void
    {
        $field = new PhoneFieldType(['defaultCountry' => 'DE']);
        $result = $field->normalize('030 1234567');

        $this->assertNotNull($result);
        $this->assertSame('+49301234567', $result['e164']);
        $this->assertSame('DE', $result['country']);
    }

    public function testNormalizeFallsBackToConfiguredDefaultForUnknownCountry(): void
    {
        $field = new PhoneFieldType(['defaultCountry' => 'CH']);
        // An unknown posted ISO must not pick a bogus dial code; it falls back
        // to the configured default country (CH = +41).
        $result = $field->normalize(['country' => 'ZZ', 'number' => '079 123 45 67']);

        $this->assertNotNull($result);
        $this->assertSame('+41791234567', $result['e164']);
        $this->assertSame('CH', $result['country']);
    }

    public function testNormalizeEmptyIsNull(): void
    {
        $field = new PhoneFieldType(['defaultCountry' => 'CH']);
        $this->assertNull($field->normalize(['country' => 'CH', 'number' => '   ']));
        $this->assertNull($field->normalize(''));
        $this->assertNull($field->normalize(null));
    }

    public function testNormalizeStoredValueDelegatesToNormalize(): void
    {
        $field = new PhoneFieldType(['defaultCountry' => 'CH']);
        $stored = $field->normalizeStoredValue(['country' => 'CH', 'number' => '079 123 45 67']);

        $this->assertIsArray($stored);
        $this->assertSame('+41791234567', $stored['e164']);
    }

    public function testConfigAccessors(): void
    {
        $field = new PhoneFieldType([
            'defaultCountry' => 'de',
            'allowedCountries' => 'CH, de, ZZ, ',
            'showCountrySelector' => true,
            'minDigits' => 8,
            'maxDigits' => 14,
        ]);

        $this->assertSame('DE', $field->defaultCountry());
        // Unknown 'ZZ' dropped, blanks dropped, upper-cased, de-duped.
        $this->assertSame(['CH', 'DE'], $field->allowedCountries());
        $this->assertTrue($field->showCountrySelector());
        $this->assertSame(8, $field->minDigits());
        $this->assertSame(14, $field->maxDigits());
    }

    public function testConfigDefaults(): void
    {
        $field = new PhoneFieldType([]);

        $this->assertSame('CH', $field->defaultCountry());
        $this->assertSame([], $field->allowedCountries());
        $this->assertFalse($field->showCountrySelector());
        $this->assertSame(PhoneFieldType::DEFAULT_MIN_DIGITS, $field->minDigits());
        $this->assertSame(PhoneFieldType::DEFAULT_MAX_DIGITS, $field->maxDigits());
    }

    public function testRenderInputWithoutSelector(): void
    {
        $field = new PhoneFieldType([
            'required' => true,
            'placeholder' => '+41 79 123 45 67',
        ]);
        $html = $field->renderInput('field_5');

        $this->assertStringContainsString('type="tel"', $html);
        $this->assertStringContainsString('name="field_5"', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('placeholder="+41 79 123 45 67"', $html);
        $this->assertStringNotContainsString('<select', $html);
    }

    public function testRenderInputEscapesAttributes(): void
    {
        $field = new PhoneFieldType(['placeholder' => '"><script>']);
        $html = $field->renderInput('field_5');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderInputPrefillsRawFromStoredValue(): void
    {
        $field = new PhoneFieldType([]);
        $html = $field->renderInput('field_5', [
            'raw' => '079 123 45 67',
            'e164' => '+41791234567',
            'country' => 'CH',
        ]);

        $this->assertStringContainsString('value="079 123 45 67"', $html);
    }

    public function testExportValuePicksE164(): void
    {
        $field = new PhoneFieldType();
        $this->assertSame('+41791234567', $field->exportValue([
            'raw' => '079 123 45 67',
            'e164' => '+41791234567',
            'country' => 'CH',
        ]));
        $this->assertSame('', $field->exportValue(null));
        $this->assertSame('', $field->exportValue([]));
    }
}

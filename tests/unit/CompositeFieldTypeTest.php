<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\fields\AddressFieldType;
use fabianhaef\simpleform\fields\CompositeFieldType;
use fabianhaef\simpleform\fields\NameFieldType;
use PHPUnit\Framework\TestCase;

/**
 * #126 — pure (Craft-free) coverage of the composite Name/Address field types:
 * enabled-sub-field resolution, render markup (ids, `<label for>`, `<fieldset>`),
 * and posted-value serialization (enabled-key clamping). Each config supplies
 * explicit sub-labels so no Craft::t default-label path is hit; the localized
 * default labels, country `<select>`, and server validation are exercised in the
 * integration suite, which boots a real Craft.
 */
class CompositeFieldTypeTest extends TestCase
{
    /**
     * A Name config with explicit labels: first + last enabled, middle off.
     *
     * @return array<string, mixed>
     */
    private static function nameConfig(): array
    {
        return [
            'subFields' => [
                'first' => ['enabled' => true, 'required' => true, 'label' => 'First'],
                'middle' => ['enabled' => false, 'required' => false, 'label' => 'Middle'],
                'last' => ['enabled' => true, 'required' => true, 'label' => 'Last'],
            ],
        ];
    }

    public function testEnabledSubFieldsRespectConfigOverlay(): void
    {
        $field = new NameFieldType(self::nameConfig());
        $enabled = $field->enabledSubFields();

        // Only enabled sub-fields appear, in declaration order; disabled dropped.
        $this->assertSame(['first', 'last'], array_keys($enabled));
        $this->assertSame('First', $enabled['first']['label']);
        $this->assertTrue($enabled['first']['required']);
        $this->assertSame(CompositeFieldType::class, get_parent_class($field));
    }

    public function testRenderEmitsLabelledSubInputsInAFieldset(): void
    {
        $html = (new NameFieldType(self::nameConfig()))->renderInput('field_42');

        $this->assertStringContainsString('<fieldset class="sf-composite" aria-labelledby="field_42-legend">', $html);
        // Each sub-input: unique id + explicit <label for> (a11y).
        $this->assertStringContainsString('<label for="field_42-first">First</label>', $html);
        $this->assertStringContainsString('id="field_42-first" name="field_42[first]"', $html);
        $this->assertStringContainsString('<label for="field_42-last">Last</label>', $html);
        $this->assertStringContainsString('name="field_42[last]"', $html);
        // Required sub-fields carry the required attribute.
        $this->assertMatchesRegularExpression('/name="field_42\[first\]"[^>]* required/', $html);
        // Disabled sub-field never renders.
        $this->assertStringNotContainsString('field_42-middle', $html);
    }

    public function testRenderPrefillsPostedValuesEscaped(): void
    {
        $html = (new NameFieldType(self::nameConfig()))->renderInput('field_42', [
            'first' => 'First',
            'last' => '"<x>',
        ]);

        $this->assertStringContainsString('value="First"', $html);
        $this->assertStringContainsString('value="&quot;&lt;x&gt;"', $html);
    }

    public function testSerializeValueKeepsOnlyEnabledKeys(): void
    {
        $clean = (new NameFieldType(self::nameConfig()))->serializeValue([
            'first' => 'First',
            'last' => 'Last',
            'middle' => 'Ignored',  // disabled — dropped
            'evil' => 'crafted',    // unknown — dropped
        ]);

        $this->assertSame(['first' => 'First', 'last' => 'Last'], $clean);
    }

    public function testSerializeValueCoercesNonArrayToEmptyParts(): void
    {
        $clean = (new NameFieldType(self::nameConfig()))->serializeValue('not-an-array');

        $this->assertSame(['first' => '', 'last' => ''], $clean);
    }

    public function testAddressDefaultsEnableAllStructuralParts(): void
    {
        // Explicit labels so the resolution stays Craft::t-free.
        $config = ['subFields' => [
            'line1' => ['enabled' => true, 'label' => 'Line 1'],
            'line2' => ['enabled' => false, 'label' => 'Line 2'],
            'city' => ['enabled' => true, 'label' => 'City'],
            'state' => ['enabled' => false, 'label' => 'Region'],
            'postalCode' => ['enabled' => true, 'label' => 'Postal'],
            'country' => ['enabled' => true, 'label' => 'Country'],
        ]];

        $enabled = (new AddressFieldType($config))->enabledSubFields();

        $this->assertSame(['line1', 'city', 'postalCode', 'country'], array_keys($enabled));
        // country is a select kind.
        $this->assertSame('select', $enabled['country']['kind']);
        $this->assertSame('text', $enabled['line1']['kind']);
    }
}

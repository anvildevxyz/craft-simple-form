<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\fields\CalculationFieldType;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the Calculation field's compute/format helpers. Pure
 * (no Craft container needed): the field type composes the safe Formula engine
 * and applies display formatting.
 */
class CalculationFieldTypeTest extends TestCase
{
    public function testRegistrationMetadata(): void
    {
        $this->assertSame('calculation', CalculationFieldType::getType());
        $this->assertSame('Calculation', CalculationFieldType::getLabel());
    }

    public function testComputeOverHandleMap(): void
    {
        $field = new CalculationFieldType(['formula' => '{quantity} * {unitPrice}']);
        $this->assertSame(30.0, $field->compute(['quantity' => 3, 'unitPrice' => 10]));
    }

    public function testComputeWithNumericSelectValue(): void
    {
        $field = new CalculationFieldType(['formula' => '{tier} + 5']);
        $this->assertSame(30.0, $field->compute(['tier' => '25']));
    }

    public function testComputeWithMissingReference(): void
    {
        $field = new CalculationFieldType(['formula' => '{a} * {b}']);
        $this->assertSame(0.0, $field->compute(['a' => 5]));
    }

    public function testComputeWithNonNumericReference(): void
    {
        $field = new CalculationFieldType(['formula' => '{name} + 1']);
        $this->assertSame(1.0, $field->compute(['name' => 'Bob']));
    }

    public function testComputeDivideByZero(): void
    {
        $field = new CalculationFieldType(['formula' => '{a} / {b}']);
        $this->assertSame(0.0, $field->compute(['a' => 10, 'b' => 0]));
    }

    public function testComputeMalformedFormulaYieldsZero(): void
    {
        // Should never happen post-validation, but compute must stay total.
        $field = new CalculationFieldType(['formula' => 'phpinfo()']);
        $this->assertSame(0.0, $field->compute([]));
    }

    public function testComputeEmptyFormula(): void
    {
        $field = new CalculationFieldType(['formula' => '']);
        $this->assertSame(0.0, $field->compute([]));
    }

    // --- Formatting ------------------------------------------------------

    public function testFormatDecimals(): void
    {
        $field = new CalculationFieldType(['decimals' => 2]);
        $this->assertSame('30.00', $field->format(30.0));
        $this->assertSame('2.35', $field->format(2.345));
    }

    public function testFormatPrefixSuffix(): void
    {
        $field = new CalculationFieldType(['decimals' => 2, 'prefix' => 'CHF ', 'suffix' => ' net']);
        $this->assertSame('CHF 30.00 net', $field->format(30.0));
    }

    public function testFormatThousandsSeparator(): void
    {
        $field = new CalculationFieldType(['decimals' => 0, 'thousandsSeparator' => true]);
        $this->assertSame('1,000', $field->format(1000.0));
    }

    public function testFormatNegative(): void
    {
        $field = new CalculationFieldType(['decimals' => 2, 'prefix' => 'CHF ']);
        $this->assertSame('CHF -5.00', $field->format(-5.0));
    }

    public function testDecimalsClampedToRange(): void
    {
        $field = new CalculationFieldType(['decimals' => 99]);
        $this->assertSame('1.000000', $field->format(1.0));

        $field = new CalculationFieldType(['decimals' => -5]);
        $this->assertSame('1', $field->format(1.0));
    }

    public function testReferencesHarvest(): void
    {
        $field = new CalculationFieldType(['formula' => '{quantity} * {unitPrice}']);
        $this->assertSame(['quantity', 'unitPrice'], $field->references());
    }

    public function testReferencesMalformedFormulaIsEmpty(): void
    {
        $field = new CalculationFieldType(['formula' => 'phpinfo()']);
        $this->assertSame([], $field->references());
    }

    public function testRenderInputCarriesDataAttributes(): void
    {
        $field = new CalculationFieldType([
            'formula' => '{quantity} * {unitPrice}',
            'decimals' => 2,
            'prefix' => 'CHF ',
        ]);
        $html = $field->renderInput('field_5', null);

        $this->assertStringContainsString('data-sf-formula="{quantity} * {unitPrice}"', $html);
        $this->assertStringContainsString('data-sf-refs="[&quot;quantity&quot;,&quot;unitPrice&quot;]"', $html);
        $this->assertStringContainsString('<output', $html);
        $this->assertStringContainsString('type="hidden" name="field_5"', $html);
        $this->assertStringContainsString('CHF 0.00', $html);
    }
}

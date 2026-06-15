<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\models\FieldModel;
use PHPUnit\Framework\TestCase;

/**
 * Per-site validation message override (issue #59). Covers the pure collapse
 * seam {@see FieldModel::applyOverride()} — the rest of the feature (localized
 * defaults via Craft::t, per-site persistence) is exercised in the integration
 * suite, which boots a real Craft with the translation catalogs loaded.
 */
class ValidationMessageTest extends TestCase
{
    public function testOverrideReplacesDefaultErrorsWhenFieldIsInvalid(): void
    {
        $errors = FieldModel::applyOverride(['This field is required.'], 'Bitte ausfüllen.');
        $this->assertSame(['Bitte ausfüllen.'], $errors);
    }

    public function testOverrideCollapsesMultipleErrorsToOneMessage(): void
    {
        $errors = FieldModel::applyOverride(
            ['Please enter a valid number.', 'Must be at least 5.'],
            'Enter a number from 5 to 10.'
        );
        $this->assertSame(['Enter a number from 5 to 10.'], $errors);
    }

    public function testNoOverrideLeavesLocalizedDefaultsUntouched(): void
    {
        $defaults = ['Ce champ est obligatoire.'];
        $this->assertSame($defaults, FieldModel::applyOverride($defaults, null));
        $this->assertSame($defaults, FieldModel::applyOverride($defaults, ''));
        // A whitespace-only override is treated as unset, never blanking the error.
        $this->assertSame($defaults, FieldModel::applyOverride($defaults, '   '));
    }

    public function testValidFieldStaysValidEvenWithAnOverrideSet(): void
    {
        // No errors in -> no errors out: the override only speaks on failure.
        $this->assertSame([], FieldModel::applyOverride([], 'Custom message'));
    }
}

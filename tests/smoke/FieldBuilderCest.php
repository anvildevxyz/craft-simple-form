<?php

namespace fabianhaef\simpleform\tests\smoke;

use SmokeTester;

/**
 * Field builder modal: add/configure every field type. CP form-builder UI.
 *
 * Skipped in the functional smoke suite: this scenario drives the JS Control
 * Panel / a real browser flow that the console-booted Codeception actor cannot
 * exercise. It is covered end-to-end by the Playwright craft-smoke-test
 * scenarios under docs/smoke-tests/. The data-layer behaviour behind it is
 * additionally covered by the tests/integration suite.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FieldBuilderCest
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    private const SKIP_REASON = 'CP UI / browser-only — covered by the Playwright craft-smoke-test scenarios in docs/smoke-tests/';

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testAddTextField(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testAddEmailField(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testAddTextareaField(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testAddSelectField(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testAddCheckboxField(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testAddRadioField(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testAddDateField(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testAddNumberField(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testHandleAutoGeneration(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testValidationErrors(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testRequiredFieldCheckbox(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testCreateFormWithMultipleFields(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testModalCancel(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testFieldTypeConfigSections(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }
}

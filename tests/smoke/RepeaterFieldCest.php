<?php

namespace anvildev\simpleform\tests\smoke;

use SmokeTester;

/**
 * Repeater field builder + inner-type rules. CP form-builder UI.
 *
 * Skipped in the functional smoke suite: this scenario drives the JS Control
 * Panel / a real browser flow that the console-booted Codeception actor cannot
 * exercise. It is covered end-to-end by the Playwright craft-smoke-test
 * the browser smoke suite. The data-layer behaviour behind it is
 * additionally covered by the tests/integration suite.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class RepeaterFieldCest
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    private const SKIP_REASON = 'CP UI / browser-only — covered by the browser smoke suite';

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testSubmitTwoRowsPersistsArrayOfRowObjects(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testZeroRowsUnderMinIsRejected(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testInvalidEmailCellMapsToRowError(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testExportContainsRepeaterJson(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testBuilderDoesNotOfferFileAsInnerType(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }
}

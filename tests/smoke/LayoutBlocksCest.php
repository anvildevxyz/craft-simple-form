<?php

namespace anvildev\simpleform\tests\smoke;

use SmokeTester;

/**
 * Layout blocks rendered on a real site request. Browser/Twig-tag only.
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
class LayoutBlocksCest
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

    public function testPublicFormRendersLayoutBlocksSafelyInOrder(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testSubmissionAndExportSkipLayoutBlocks(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testCalloutBlockRendersAndStoresNoValue(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }
}

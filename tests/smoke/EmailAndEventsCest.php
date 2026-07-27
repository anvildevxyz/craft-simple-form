<?php

namespace anvildev\simpleform\tests\smoke;

use SmokeTester;

/**
 * Notification email delivery + event hooks via the rendered front-end + Mailpit.
 *
 * Skipped in the functional smoke suite: this scenario drives the JS Control
 * Panel / a real browser flow that the console-booted Codeception actor cannot
 * exercise. It is covered end-to-end by the Playwright craft-smoke-test
 * scenarios under docs/smoke-tests/. The data-layer behaviour behind it is
 * additionally covered by the tests/integration suite.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class EmailAndEventsCest
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

    public function testEmailSentOnSubmission(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testEmailContainsSubmissionData(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testEmailSubjectConfigured(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testEmailReplyToSet(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testEventBeforeSubmissionSave(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testEventAfterSubmissionSave(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testEventContainsSubmissionData(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testWebhookTriggeredOnSubmission(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testCrmIntegrationViaEvent(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testCustomValidationViaEvent(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testEventModificationOfSubmission(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }

    public function testMultipleEventListeners(SmokeTester $I): void
    {
        $I->markTestSkipped(self::SKIP_REASON);
    }
}

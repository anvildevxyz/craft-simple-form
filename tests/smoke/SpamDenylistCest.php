<?php

namespace fabianhaef\simpleform\tests\smoke;

use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\Plugin;
use SmokeTester;

/**
 * Spam Denylist Smoke Tests (#140, functional).
 *
 * Exercises the settings-driven denylists through the public submit path
 * ({@see \fabianhaef\simpleform\services\SubmissionService::createFromRequest()},
 * field values posted as `field_<id>`): a flagged submission is quarantined with
 * its reason, a clean one passes as New, and block mode drops the row silently.
 * Forms and fields are seeded through the data layer (see {@see BaseSmokeCest}).
 *
 * The CP settings/review-queue UI and Mailpit delivery are covered by the
 * Playwright craft-smoke-test scenarios; the enforcement is covered here and in
 * the integration DenylistEnforcementTest.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class SpamDenylistCest extends BaseSmokeCest
{
    // =========================================================================
    // PRIVATE PROPERTIES
    // =========================================================================

    private int $formId;

    private string $formHandle;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $form = $this->createForm('Spam Test Form', 'spamTest' . uniqid(), 'admin@test.com');
        $this->formId = (int)$form->id;
        $this->formHandle = $form->handle;
    }

    public function testKeywordFlagsSubmissionForReview(SmokeTester $I): void
    {
        $messageId = $this->createField($this->formId, 'text', 'message', 'Message');

        $this->withDenylist(['blockedKeywords' => "casino\ncrypto"], function() use ($I, $messageId): void {
            $result = $this->submitRequest($this->formHandle, ['field_' . $messageId => 'Casino night! Join us.']);

            $I->assertInstanceOf(Submission::class, $result['submission']);
            $I->assertSame(SubmissionStatus::SPAM, $result['submission']->readStatus);
            $I->assertSame('keyword:casino', $result['submission']->spamReason);
        });
    }

    public function testBlockedDomainQuarantinesAndCleanEmailPasses(SmokeTester $I): void
    {
        $emailId = $this->createField($this->formId, 'email', 'email', 'Email');

        $this->withDenylist(['blockedEmails' => '@mailinator.com'], function() use ($I, $emailId): void {
            $blocked = $this->submitRequest($this->formHandle, ['field_' . $emailId => 'bob@mailinator.com']);
            $I->assertSame(SubmissionStatus::SPAM, $blocked['submission']->readStatus);
            $I->assertSame('email:bob@mailinator.com', $blocked['submission']->spamReason);

            $clean = $this->submitRequest($this->formHandle, ['field_' . $emailId => 'bob@gmail.com']);
            $I->assertSame(SubmissionStatus::NEW, $clean['submission']->readStatus);
            $I->assertNull($clean['submission']->spamReason);
        });
    }

    public function testBlockModeDropsSilently(SmokeTester $I): void
    {
        $messageId = $this->createField($this->formId, 'text', 'message', 'Message');

        $this->withDenylist(
            ['blockedKeywords' => 'casino', 'denylistMode' => Settings::DENYLIST_BLOCK],
            function() use ($I, $messageId): void {
                $result = $this->submitRequest($this->formHandle, ['field_' . $messageId => 'casino casino casino']);

                // Visitor gets no signal: no submission, no errors — and no row.
                $I->assertNull($result['submission'], 'block mode drops the submission');
                $I->assertNull($result['errors']);

                $count = (new Query())
                    ->from('{{%simpleform_submissions}}')
                    ->where(['formId' => $this->formId])
                    ->count();
                $I->assertSame(0, (int)$count, 'no row persisted in block mode');
            },
        );
    }

    public function testDisabledDenylistLetsKeywordThrough(SmokeTester $I): void
    {
        $messageId = $this->createField($this->formId, 'text', 'message', 'Message');

        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->enableDenylists = false;
            $settings->blockedKeywords = 'casino';

            $result = $this->submitRequest($this->formHandle, ['field_' . $messageId => 'casino night']);

            $I->assertInstanceOf(Submission::class, $result['submission']);
            $I->assertSame(SubmissionStatus::NEW, $result['submission']->readStatus);
        } finally {
            $settings->setAttributes($original, false);
        }
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Enable denylists (flag mode) with the given overrides, run the closure, then
     * restore the original settings.
     *
     * @param array<string, mixed> $overrides
     */
    private function withDenylist(array $overrides, callable $fn): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->getAttributes();
        try {
            $settings->enableDenylists = true;
            $settings->denylistMode = Settings::DENYLIST_FLAG;
            $settings->setAttributes($overrides, false);
            $fn();
        } finally {
            $settings->setAttributes($original, false);
        }
    }
}

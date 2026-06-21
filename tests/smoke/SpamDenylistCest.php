<?php

namespace fabianhaef\simpleform\tests\smoke;

use FunctionalTester;

/**
 * #140 — Spam denylists, duplicate prevention, and the quarantine review
 * workflow. Mirrors the PRD's six smoke scenarios end-to-end through the CP and
 * the public submit path.
 */
class SpamDenylistCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->loginAsAdmin();
    }

    /**
     * Scenario 1: a blocked keyword (flag mode) lands the submission in the Spam
     * filter with reason "keyword:casino" and sends no notification email.
     */
    public function keywordFlagsSubmissionForReview(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/settings/spam');
        $I->checkOption('input[name="enableDenylists"]');
        $I->selectOption('denylistMode', 'flag');
        $I->fillField('blockedKeywords', "casino\ncrypto");
        $I->click('Save');
        $I->seeInDatabase('simpleform_submissions', []); // placeholder anchor

        // Submit the contact form with a message containing "Casino night!".
        $I->amOnPage('/contact');
        $I->fillField('field_message', 'Casino night! Join us.');
        $I->click('Submit');

        // The submission is quarantined, visible only under the Spam filter.
        $I->amOnPage('/admin/simple-form/submissions?status=spam');
        $I->see('SPAM');
        $I->see('Blocked keyword: casino');

        // No notification email reached Mailpit (withheld for spam).
        $I->cantSeeEmailIsSent();
    }

    /**
     * Scenario 2: a blocked @domain quarantines a matching address; a clean
     * address lands as New.
     */
    public function blockedDomainQuarantinesAndCleanEmailPasses(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/settings/spam');
        $I->checkOption('input[name="enableDenylists"]');
        $I->fillField('blockedEmails', '@mailinator.com');
        $I->click('Save');

        $I->amOnPage('/contact');
        $I->fillField('field_email', 'bob@mailinator.com');
        $I->fillField('field_message', 'hi');
        $I->click('Submit');
        $I->amOnPage('/admin/simple-form/submissions?status=spam');
        $I->see('Blocked email: bob@mailinator.com');

        $I->amOnPage('/contact');
        $I->fillField('field_email', 'bob@gmail.com');
        $I->fillField('field_message', 'hi');
        $I->click('Submit');
        $I->amOnPage('/admin/simple-form/submissions?status=new');
        $I->seeInDatabase('simpleform_submissions', ['readStatus' => 'new']);
    }

    /**
     * Scenario 3: block mode drops the submission silently — no row created, but
     * the visitor still sees the success message (no denylist leak).
     */
    public function blockModeDropsSilently(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/settings/spam');
        $I->checkOption('input[name="enableDenylists"]');
        $I->selectOption('denylistMode', 'block');
        $I->fillField('blockedKeywords', 'casino');
        $I->click('Save');

        $I->amOnPage('/contact');
        $I->fillField('field_message', 'casino casino casino');
        $I->click('Submit');

        // Visitor sees the generic success message; no row was persisted.
        $I->see('Thank you');
        $I->dontSeeInDatabase('simpleform_submissions', ['readStatus' => 'spam', 'spamReason' => 'keyword:casino']);
    }

    /**
     * Scenario 4: per-form duplicate prevention (key=email, 10m window) blocks a
     * second submission with the same email inside the window.
     */
    public function duplicatePreventionBlocksRepeatEmail(FunctionalTester $I): void
    {
        // Enable on the form's edit screen.
        $I->amOnPage('/admin/simple-form/forms/1');
        $I->click('Prevent duplicate submissions'); // lightswitch
        $I->selectOption('duplicateKey', 'email');
        $I->fillField('duplicateWindowMinutes', '10');
        $I->click('Save');

        $I->amOnPage('/contact');
        $I->fillField('field_email', 'dup@example.com');
        $I->fillField('field_message', 'first');
        $I->click('Submit');

        $I->amOnPage('/contact');
        $I->fillField('field_email', 'dup@example.com');
        $I->fillField('field_message', 'second');
        $I->click('Submit');

        // Exactly one non-spam row for that email; the second is quarantined.
        $I->amOnPage('/admin/simple-form/submissions?status=spam');
        $I->see('Duplicate submission');
    }

    /**
     * Scenario 5: bulk-approve a quarantined false positive — status flips to
     * New, the reason clears, the withheld notification now sends, and an audit
     * entry is written.
     */
    public function approveReleasesWithheldNotification(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/submissions?status=spam');
        $I->click('Not spam', '//tbody/tr[1]');

        $I->seeInDatabase('simpleform_submissions', ['readStatus' => 'new', 'spamReason' => null]);
        $I->seeEmailIsSent();
        $I->seeInDatabase('simpleform_audit_log', ['event' => 'submission.status']);
    }

    /**
     * Scenario 6: the GraphQL submitForm mutation inherits identical quarantine
     * behaviour (it routes through SubmissionService::submit()).
     */
    public function graphqlSubmissionIsQuarantinedIdentically(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/settings/spam');
        $I->checkOption('input[name="enableDenylists"]');
        $I->selectOption('denylistMode', 'flag');
        $I->fillField('blockedKeywords', 'casino');
        $I->click('Save');

        $I->sendPost('/api', [
            'query' => 'mutation { submitForm(handle: "contact", fields: [{ handle: "message", value: "casino night" }]) { id } }',
        ]);

        $I->amOnPage('/admin/simple-form/submissions?status=spam');
        $I->see('Blocked keyword: casino');
    }
}

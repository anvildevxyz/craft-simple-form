<?php

namespace fabianhaef\simpleform\tests\smoke;

use FunctionalTester;

/**
 * Smoke scenarios for login-required + per-user submission limits (#135).
 *
 * Covers the visitor-facing behaviour: a gated form hides itself behind a
 * login-required notice (with a working login link) for guests and rejects a
 * crafted anonymous POST; a per-user cap shows the limit message instead of the
 * form to a user who has hit it and rejects a second POST.
 */
class UserLimitsCest
{
    /**
     * Guest hitting a login-required form: sees the message + login link, no
     * <form>; a crafted POST is rejected and persists nothing.
     */
    public function loginRequiredHidesFormAndRejectsGuestPost(FunctionalTester $I)
    {
        $I->loginAsAdmin();

        // Build a login-required form via the CP.
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Gated Smoke');
        $I->fillField('handle', 'gated-smoke');
        $I->click('Save');

        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Gated Smoke');
        $I->checkOption('input[name="requireLogin"]');
        $I->click('Save');

        // As a guest, the form is replaced by the login-required notice + link.
        $I->logout();
        $I->amOnPage('/forms/gated-smoke');
        $I->seeElement('.simple-form--login-required');
        $I->dontSeeElement('form.simple-form');
        $I->seeElement('.simple-form--login-required a');

        // A crafted anonymous POST is rejected server-side.
        $before = $I->grabNumRecords('simpleform_submissions', ['formId' => $this->formId($I, 'gated-smoke')]);
        $I->sendAjaxPostRequest('/index.php?p=actions/simple-form/submit', [
            'formHandle' => 'gated-smoke',
        ]);
        $after = $I->grabNumRecords('simpleform_submissions', ['formId' => $this->formId($I, 'gated-smoke')]);
        $I->assertSame($before, $after, 'Anonymous POST to a login-required form must not persist a row');
    }

    /**
     * A user at their per-user cap sees the limit-reached message instead of the
     * form, and a second POST is rejected.
     */
    public function perUserCapShowsLimitMessageAndRejectsSecondPost(FunctionalTester $I)
    {
        $I->loginAsAdmin();

        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Once Smoke');
        $I->fillField('handle', 'once-smoke');
        $I->click('Save');

        // Add one field so the form is submittable.
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Once Smoke');
        $I->click('Add Field');
        $I->fillField('label', 'Note');
        $I->fillField('handle', 'note');
        $I->selectOption('type', 'text');
        $I->click('Save Field');

        // Cap at one submission per user.
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Once Smoke');
        $I->fillField('submissionsPerUser', '1');
        $I->click('Save');

        $formId = $this->formId($I, 'once-smoke');

        // The admin (a logged-in user) submits once.
        $I->haveInDatabase('simpleform_submissions', [
            'formId' => $formId,
            'siteId' => 1,
            'userId' => 1,
            'data' => json_encode(['field_1' => ['label' => 'Note', 'type' => 'text', 'value' => 'first']]),
            'readStatus' => 'new',
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => 'smoke-once-' . uniqid(),
        ]);

        // Re-rendering the form now shows the limit message instead of the form.
        $I->amOnPage('/forms/once-smoke');
        $I->seeElement('.simple-form--limit-reached');
        $I->dontSeeElement('form.simple-form');
    }

    private function formId(FunctionalTester $I, string $handle): int
    {
        return (int) $I->grabFromDatabase('simpleform_forms', 'id', ['handle' => $handle]);
    }
}

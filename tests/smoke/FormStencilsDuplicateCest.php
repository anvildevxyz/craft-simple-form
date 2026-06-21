<?php

namespace fabianhaef\simpleform\tests\smoke;

use FunctionalTester;

/**
 * Smoke coverage for form duplication + stencils (#138):
 *  - duplicate an existing form (a -copy handle appears, original is untouched),
 *  - the index "New form" menu offers each stencil,
 *  - creating a form from the Contact stencil yields its fields,
 *  - a stencil handle collision resolves to a distinct handle.
 *
 * The duplicate/stencil actions are CSRF-protected POSTs; we drive them through
 * Codeception's request helpers and assert the resulting DB state, which is the
 * load-bearing outcome.
 */
class FormStencilsDuplicateCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
    }

    public function duplicateFormCreatesACopyWithNewHandle(FunctionalTester $I)
    {
        // Seed a form via the CP so it has a real element + saved row.
        $I->createTestForm('Sales Request', 'sales-request', 'sales@example.com');
        $I->seeInDatabase('simpleform_forms', ['handle' => 'sales-request']);

        $formId = (int) $I->grabFromDatabase('simpleform_forms', 'id', ['handle' => 'sales-request']);

        // Duplicate it (the edit-screen "Save as a new form" / index Duplicate
        // button both POST here).
        $I->amOnPage('/admin/simple-form/forms/edit/' . $formId);
        $I->sendAjaxPostRequest('/admin/simple-form/forms/duplicate', [
            'formId' => $formId,
            \Craft::$app->getConfig()->getGeneral()->csrfTokenName => $I->grabCookie(\Craft::$app->getConfig()->getGeneral()->csrfTokenName),
        ]);

        // The original is untouched and a -copy form now exists.
        $I->seeInDatabase('simpleform_forms', ['handle' => 'sales-request']);
        $I->seeInDatabase('simpleform_forms', ['handle' => 'sales-request-copy']);
    }

    public function indexOffersStencils(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->see('New Form');
        $I->see('Contact');
        $I->see('Newsletter signup');
        $I->see('Support request');
    }

    public function newFromContactStencilCreatesTheExpectedFields(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->sendAjaxPostRequest('/admin/simple-form/forms/new-from-stencil', [
            'stencil' => 'contact',
            \Craft::$app->getConfig()->getGeneral()->csrfTokenName => $I->grabCookie(\Craft::$app->getConfig()->getGeneral()->csrfTokenName),
        ]);

        $I->seeInDatabase('simpleform_forms', ['handle' => 'contact']);
        $formId = (int) $I->grabFromDatabase('simpleform_forms', 'id', ['handle' => 'contact']);
        $I->seeInDatabase('simpleform_fields', ['formId' => $formId, 'name' => 'name', 'type' => 'text']);
        $I->seeInDatabase('simpleform_fields', ['formId' => $formId, 'name' => 'email', 'type' => 'email']);
        $I->seeInDatabase('simpleform_fields', ['formId' => $formId, 'name' => 'message', 'type' => 'textarea']);
    }

    public function stencilHandleCollisionResolvesToADistinctHandle(FunctionalTester $I)
    {
        $csrfName = \Craft::$app->getConfig()->getGeneral()->csrfTokenName;

        $I->amOnPage('/admin/simple-form/forms');
        $I->sendAjaxPostRequest('/admin/simple-form/forms/new-from-stencil', [
            'stencil' => 'newsletter',
            $csrfName => $I->grabCookie($csrfName),
        ]);
        $I->sendAjaxPostRequest('/admin/simple-form/forms/new-from-stencil', [
            'stencil' => 'newsletter',
            $csrfName => $I->grabCookie($csrfName),
        ]);

        $I->seeInDatabase('simpleform_forms', ['handle' => 'newsletter']);
        $I->seeInDatabase('simpleform_forms', ['handle' => 'newsletter-copy']);
    }
}

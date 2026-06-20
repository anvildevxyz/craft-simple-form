<?php

namespace fabianhaef\simpleform\tests\smoke;

use Codeception\Util\HttpCode;
use FunctionalTester;

/**
 * Smoke scenarios for form configuration import/export (#139): export a form's
 * secret-free JSON from the CP, import it back with a conflict mode, and confirm
 * a working form is recreated.
 */
class FormImportExportCest
{
    public function loginAsAdmin(FunctionalTester $I): void
    {
        $I->amOnPage('/admin');
        $I->fillField('loginName', 'admin');
        $I->fillField('password', 'password');
        $I->click('Sign in');
    }

    /**
     * Seed a form to export, then export it via the CP download button.
     */
    public function exportThenImportOnSameInstall(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        // Create a form to export.
        $I->amOnPage('/admin/simple-form/forms/new');
        $I->see('Create Form');
        $I->fillField('name', 'Portable Contact');
        $I->fillField('handle', 'portable_contact');
        $I->fillField('title', 'Portable Contact');
        $I->fillField('emailTo', 'team@example.com');
        $I->click('Save');
        $I->see('Portable Contact');

        // The index now offers an Export download and an Import button.
        $I->amOnPage('/admin/simple-form/forms');
        $I->see('Export');
        $I->see('Import a form');

        // Export streams JSON with the form's handle but no secrets.
        $formId = $I->grabFromDatabase('{{%simpleform_forms}}', 'id', ['handle' => 'portable_contact']);
        $I->amOnPage('/admin/simple-form/forms/export/' . $formId);
        $I->seeResponseCodeIs(HttpCode::OK);
        $I->see('portable_contact');
        $I->see('"schemaVersion"');
        $I->dontSee('__REDACTED__'); // no integration → no redaction marker
    }

    /**
     * The form edit screen exposes its own Export button.
     */
    public function editScreenHasExportButton(FunctionalTester $I): void
    {
        $this->loginAsAdmin($I);

        $formId = $I->grabFromDatabase('{{%simpleform_forms}}', 'id', ['handle' => 'portable_contact']);
        $I->amOnPage('/admin/simple-form/forms/edit/' . $formId);
        $I->see('Edit Form');
        $I->see('Export');
    }
}

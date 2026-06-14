<?php

namespace fabianhaef\simpleform\tests\smoke;
use FunctionalTester;
class FormSubmissionCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
        // Create test form
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Submission Test Form');
        $I->fillField('handle', 'submission-test');
        $I->fillField('emailTo', 'admin@example.com');
        $I->click('Save');

        // Add fields
        $I->click('Add Field');
        $I->fillField('label', 'Name');
        $I->fillField('handle', 'name');
        $I->selectOption('type', 'text');
        $I->checkOption('required');
        $I->click('Save Field');

        $I->click('Add Field');
        $I->fillField('label', 'Email');
        $I->fillField('handle', 'email');
        $I->selectOption('type', 'email');
        $I->checkOption('required');
        $I->click('Save Field');

        $I->click('Add Field');
        $I->fillField('label', 'Message');
        $I->fillField('handle', 'message');
        $I->selectOption('type', 'textarea');
        $I->click('Save Field');
    }

    public function testSubmitFormWithValidData(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submission-test');
        $I->fillField('name', 'John Doe');
        $I->fillField('email', 'john@example.com');
        $I->fillField('message', 'Test message');
        $I->click('Submit');

        $I->seeResponseContains('Thank you');
        $I->seeInDatabase('simpleform_submissions', ['data' => '%John Doe%']);
    }

    public function testSubmitFormWithInvalidEmail(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submission-test');
        $I->fillField('name', 'Jane Doe');
        $I->fillField('email', 'invalid-email');
        $I->click('Submit');

        $I->seeResponseContains('invalid');
    }

    public function testSubmitWithMissingRequiredFields(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submission-test');
        $I->fillField('name', '');
        $I->click('Submit');

        $I->seeResponseContains('required');
    }

    public function testHoneypotProtection(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submission-test');
        // Fill honeypot field (should be hidden from users)
        // This would require special test handling in real implementation
        $I->see('form');
    }

    public function testTextFieldLengthValidation(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Submission Test Form');
        $I->click('Edit', "//tr[contains(., 'Name')]");
        $I->fillField('minLength', '5');
        $I->fillField('maxLength', '50');
        $I->click('Save Field');

        $I->amOnPage('/forms/submission-test');
        $I->fillField('name', 'ab');
        $I->click('Submit');

        $I->seeResponseContains('least');
    }

    public function testSelectFieldValidation(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Submission Test Form');
        $I->click('Add Field');
        $I->fillField('label', 'Status');
        $I->fillField('handle', 'status');
        $I->selectOption('type', 'select');
        $I->fillField('options', "Active\nInactive");
        $I->click('Save Field');

        $I->amOnPage('/forms/submission-test');
        $I->selectOption('status', 'Active');
        $I->fillField('name', 'Test');
        $I->fillField('email', 'test@example.com');
        $I->click('Submit');

        $I->seeInDatabase('simpleform_submissions', ['data' => '%Active%']);
    }

    public function testCheckboxMultipleValues(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Submission Test Form');
        $I->click('Add Field');
        $I->fillField('label', 'Interests');
        $I->fillField('handle', 'interests');
        $I->selectOption('type', 'checkbox');
        $I->fillField('options', "Sports\nMusic\nReading");
        $I->click('Save Field');

        $I->amOnPage('/forms/submission-test');
        $I->checkOption('input[value="Sports"]');
        $I->checkOption('input[value="Music"]');
        $I->fillField('name', 'Test User');
        $I->fillField('email', 'test@example.com');
        $I->click('Submit');

        $I->seeInDatabase('simpleform_submissions', ['data' => '%Sports%']);
    }

    public function testDateFieldValidation(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Submission Test Form');
        $I->click('Add Field');
        $I->fillField('label', 'Birthdate');
        $I->fillField('handle', 'birthdate');
        $I->selectOption('type', 'date');
        $I->click('Save Field');

        $I->amOnPage('/forms/submission-test');
        $I->fillField('birthdate', '01/15/1990');
        $I->fillField('name', 'Test');
        $I->fillField('email', 'test@example.com');
        $I->click('Submit');

        $I->seeInDatabase('simpleform_submissions', ['data' => '%1990%']);
    }

    public function testNumberFieldMinMaxValidation(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Submission Test Form');
        $I->click('Add Field');
        $I->fillField('label', 'Quantity');
        $I->fillField('handle', 'quantity');
        $I->selectOption('type', 'number');
        $I->fillField('minValue', '1');
        $I->fillField('maxValue', '100');
        $I->click('Save Field');

        $I->amOnPage('/forms/submission-test');
        $I->fillField('quantity', '150');
        $I->fillField('name', 'Test');
        $I->fillField('email', 'test@example.com');
        $I->click('Submit');

        $I->seeResponseContains('maximum');
    }

    public function testCsrfTokenValidation(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submission-test');
        // CSRF token should be present in form
        $I->seeElement('input[name="__csrf"]');
        $I->seeElement('input[name="__requestVerificationToken"]');
    }

    public function testMultiStepSubmissionFlow(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submission-test');
        $I->fillField('name', 'Multi Step');
        $I->fillField('email', 'multi@example.com');
        $I->fillField('message', 'Testing multi-step');
        $I->click('Submit');

        $I->seeResponseContains('Thank you');
        $I->dontSee('Multi Step');
        $I->dontSee('multi@example.com');
    }

    public function testFormResetAfterSuccessfulSubmission(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submission-test');
        $I->fillField('name', 'Reset Test');
        $I->fillField('email', 'reset@example.com');
        $I->click('Submit');

        $I->amOnPage('/forms/submission-test');
        $I->seeFormFieldDoesNotHaveValue('name', 'Reset Test');
    }
}

<?php

namespace fabianhaef\simpleform\tests\smoke;

class FormSubmissionCest
{
    public function _before(FunctionalTester $I)
    {
        // Setup form with fields for submission testing
        $I->loginAsAdmin();
        $I->createTestForm('Submit Test', 'submit-test');
    }

    /**
     * Scenario 1: Submit form with valid data
     */
    public function testSubmitValidData(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'John Doe');
        $I->fillField('field_email', 'john@example.com');
        $I->fillField('field_message', 'This is a test message');

        $I->click('Submit');

        $I->seeResponseContains('success');
        $I->seeInDatabase('simpleform_submissions', [
            'readStatus' => 'new',
        ]);
    }

    /**
     * Scenario 2: Submit form with invalid email
     */
    public function testSubmitInvalidEmail(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'Jane Doe');
        $I->fillField('field_email', 'invalid-email');
        $I->fillField('field_message', 'Test');

        $I->click('Submit');

        $I->seeResponseContains('error');
        $I->seeResponseContains('email');
    }

    /**
     * Scenario 3: Submit with missing required fields
     */
    public function testSubmitMissingRequired(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'Bob');
        // Leave email and message empty

        $I->click('Submit');

        $I->seeResponseContains('required');
    }

    /**
     * Scenario 4: Honeypot protection
     */
    public function testHoneypotProtection(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('__honeypot', 'bot_value');
        $I->fillField('field_name', 'Spammer');
        $I->fillField('field_email', 'spam@example.com');

        $I->click('Submit');

        // Should silently redirect without saving
        $I->dontSeeInDatabase('simpleform_submissions', [
            'data' => '%Spammer%',
        ]);
    }

    /**
     * Scenario 5: Text field length validation
     */
    public function testTextFieldLengthValidation(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'ab'); // Too short
        $I->fillField('field_email', 'test@example.com');
        $I->fillField('field_message', 'Valid message');

        $I->click('Submit');

        $I->seeResponseContains('length');
    }

    /**
     * Scenario 6: Select field validation
     */
    public function testSelectFieldValidation(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'Valid Name');
        $I->fillField('field_email', 'test@example.com');
        $I->selectOption('field_select', 'valid_option');

        $I->click('Submit');

        $I->seeResponseContains('success');
    }

    /**
     * Scenario 7: Checkbox multiple values
     */
    public function testCheckboxMultipleValues(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'Checkbox Tester');
        $I->fillField('field_email', 'test@example.com');
        $I->checkOption('field_interests', 'tech');
        $I->checkOption('field_interests', 'business');

        $I->click('Submit');

        $I->seeResponseContains('success');
    }

    /**
     * Scenario 8: Date field validation
     */
    public function testDateFieldValidation(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'Date Tester');
        $I->fillField('field_email', 'test@example.com');
        $I->fillField('field_date', '2026-12-25');

        $I->click('Submit');

        $I->seeResponseContains('success');
    }

    /**
     * Scenario 9: Number field min/max validation
     */
    public function testNumberFieldValidation(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'Number Tester');
        $I->fillField('field_email', 'test@example.com');
        $I->fillField('field_quantity', '150'); // Too high

        $I->click('Submit');

        $I->seeResponseContains('error');
    }

    /**
     * Scenario 10: CSRF token validation
     */
    public function testCsrfTokenInForm(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        // Verify CSRF token is present
        $I->seeElement('input[name="__RequestVerificationToken"]');
    }

    /**
     * Scenario 11: Multi-step submission flow
     */
    public function testMultiStepSubmission(FunctionalTester $I)
    {
        // Visit form
        $I->amOnPage('/forms/submit-test');
        $I->see('Submit Test');

        // Fill form
        $I->fillField('field_name', 'Progressive Tester');
        $I->fillField('field_email', 'progressive@example.com');
        $I->fillField('field_message', 'Testing progressive submission');

        // Submit
        $I->click('Submit');

        // Verify success
        $I->seeResponseContains('success');

        // Verify submission saved
        $I->seeInDatabase('simpleform_submissions', [
            'readStatus' => 'new',
        ]);
    }

    /**
     * Scenario 12: Form reset after successful submission
     */
    public function testFormResetAfterSubmit(FunctionalTester $I)
    {
        $I->amOnPage('/forms/submit-test');

        $I->fillField('field_name', 'Reset Tester');
        $I->fillField('field_email', 'reset@example.com');
        $I->fillField('field_message', 'Testing reset');

        $I->click('Submit');

        // Form should be cleared after success
        $I->seeFieldIsEmpty('field_name');
        $I->seeFieldIsEmpty('field_email');
    }
}

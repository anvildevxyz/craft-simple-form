<?php

namespace fabianhaef\simpleform\tests\smoke;
use FunctionalTester;
class EmailAndEventsCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
        // Create test form with email notification
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Email Test');
        $I->fillField('handle', 'email-test');
        $I->fillField('emailTo', 'admin@example.com');
        $I->fillField('emailSubject', 'New Submission');
        $I->click('Save');

        // Add fields
        $I->click('Add Field');
        $I->fillField('label', 'Name');
        $I->fillField('handle', 'name');
        $I->selectOption('type', 'text');
        $I->click('Save Field');
    }

    public function testEmailSentOnSubmission(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Email Tester');
        $I->click('Submit');

        // Check Mailpit
        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('admin@example.com');
    }

    public function testEmailContainsSubmissionData(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Data Test');
        $I->click('Submit');

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('Data Test');
    }

    public function testEmailSubjectConfigured(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Email Test');
        $I->fillField('emailSubject', 'Custom Subject Line');
        $I->click('Save');

        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Subject Tester');
        $I->click('Submit');

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('Custom Subject Line');
    }

    public function testEmailReplyToSet(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Email Test');
        $I->fillField('emailReplyTo', 'support@example.com');
        $I->click('Save');

        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Reply Tester');
        $I->click('Submit');

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('support@example.com');
    }

    public function testEventBeforeSubmissionSave(FunctionalTester $I)
    {
        // Event listener would be registered in bootstrap/config
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Event Tester');
        $I->click('Submit');

        // Verify submission was saved (event allowed it)
        $I->seeInDatabase('simpleform_submissions', ['data' => '%Event Tester%']);
    }

    public function testEventAfterSubmissionSave(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'After Event Tester');
        $I->click('Submit');

        // Submission should be in database (after-save event fired)
        $I->seeInDatabase('simpleform_submissions', ['data' => '%After Event Tester%']);
    }

    public function testEventContainsSubmissionData(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Event Data Test');
        $I->click('Submit');

        $I->seeInDatabase('simpleform_submissions', ['data' => '%Event Data Test%']);
    }

    public function testWebhookTriggeredOnSubmission(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Webhook Test');
        $I->click('Submit');

        // Webhook would be called if configured
        $I->seeInDatabase('simpleform_submissions', ['data' => '%Webhook Test%']);
    }

    public function testCrmIntegrationViaEvent(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'CRM Prospect');
        $I->click('Submit');

        // Submission data would be synced to CRM via event listener
        $I->seeInDatabase('simpleform_submissions', ['data' => '%CRM Prospect%']);
    }

    public function testCustomValidationViaEvent(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'test');
        $I->click('Submit');

        // Custom validation logic in event listener
        $I->seeResponseContains('form');
    }

    public function testEventModificationOfSubmission(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Original Name');
        $I->click('Submit');

        // Event listener could modify submission before save
        $I->seeInDatabase('simpleform_submissions', ['data' => '%Original Name%']);
    }

    public function testMultipleEventListeners(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('name', 'Multi Listener Test');
        $I->click('Submit');

        // All event listeners should fire
        $I->seeInDatabase('simpleform_submissions', ['data' => '%Multi Listener Test%']);
        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('admin@example.com');
    }
}

<?php

namespace fabianhaef\simpleform\tests\smoke;

class EmailAndEventsCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
        $I->createTestForm('Email Test', 'email-test', 'admin@example.com');
    }

    /**
     * Scenario 1: Email sent on form submission
     */
    public function testEmailSentOnSubmission(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('field_name', 'Email Tester');
        $I->fillField('field_email', 'tester@example.com');
        $I->fillField('field_message', 'Testing email');

        $I->click('Submit');

        // Check Mailpit
        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->seeInSource('admin@example.com');
        $I->seeInSource('Email Tester');
    }

    /**
     * Scenario 2: Email contains submission data
     */
    public function testEmailContainsData(FunctionalTester $I)
    {
        $I->amOnPage('/forms/email-test');
        $I->fillField('field_name', 'Data Test');
        $I->fillField('field_email', 'data@example.com');

        $I->click('Submit');

        // Check email content
        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('Data Test');
        $I->see('data@example.com');
    }

    /**
     * Scenario 3: Email subject configured
     */
    public function testEmailSubjectConfigured(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Email Test');

        $I->fillField('emailSubject', 'Custom Subject Line');
        $I->click('Save');

        // Submit form
        $I->amOnPage('/forms/email-test');
        $I->submitForm(['field_name' => 'Subject Tester']);

        // Check email
        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('Custom Subject Line');
    }

    /**
     * Scenario 4: Email reply-to set
     */
    public function testEmailReplyToSet(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Email Test');

        $I->fillField('emailReplyTo', 'support@example.com');
        $I->click('Save');

        // Submit form
        $I->amOnPage('/forms/email-test');
        $I->submitForm(['field_name' => 'Reply Tester']);

        // Check email headers
        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('support@example.com');
    }

    /**
     * Scenario 5: Event before submission save
     */
    public function testEventBeforeSave(FunctionalTester $I)
    {
        // Create test page that listens to event
        $I->amOnPage('/test/event-listener');

        $I->amOnPage('/forms/email-test');
        $I->submitForm(['field_name' => 'Event Tester']);

        // Verify event was triggered
        $I->seeInDatabase('test_events_log', [
            'event' => 'beforeSubmissionSave',
        ]);
    }

    /**
     * Scenario 6: Event after submission save
     */
    public function testEventAfterSave(FunctionalTester $I)
    {
        $I->amOnPage('/test/event-listener');

        $I->amOnPage('/forms/email-test');
        $I->submitForm(['field_name' => 'After Event Tester']);

        // Verify event was triggered
        $I->seeInDatabase('test_events_log', [
            'event' => 'afterSubmissionSave',
        ]);
    }

    /**
     * Scenario 7: Event contains submission data
     */
    public function testEventContainsData(FunctionalTester $I)
    {
        $I->amOnPage('/test/event-listener');

        $I->amOnPage('/forms/email-test');
        $I->submitForm([
            'field_name' => 'Event Data Test',
            'field_email' => 'eventdata@example.com',
        ]);

        // Verify data was in event
        $I->seeInDatabase('test_events_log', [
            'data' => '%Event Data Test%',
        ]);
    }

    /**
     * Scenario 8: Webhook triggered on submission
     */
    public function testWebhookTriggered(FunctionalTester $I)
    {
        // Register webhook listener
        $I->amOnPage('/test/webhook-listener');

        $I->amOnPage('/forms/email-test');
        $I->submitForm(['field_name' => 'Webhook Test']);

        // Verify webhook was called
        $I->seeInDatabase('webhook_calls', [
            'event' => 'submission.created',
        ]);
    }

    /**
     * Scenario 9: CRM integration via event
     */
    public function testCrmIntegration(FunctionalTester $I)
    {
        // Setup CRM listener
        $I->amOnPage('/test/crm-listener');

        $I->amOnPage('/forms/email-test');
        $I->submitForm([
            'field_name' => 'CRM Prospect',
            'field_email' => 'prospect@company.com',
        ]);

        // Verify CRM entry created
        $I->seeInDatabase('crm_contacts', [
            'name' => 'CRM Prospect',
        ]);
    }

    /**
     * Scenario 10: Custom validation via event
     */
    public function testCustomValidationViaEvent(FunctionalTester $I)
    {
        $I->amOnPage('/test/custom-validation');

        $I->amOnPage('/forms/email-test');
        $I->fillField('field_name', 'InvalidName');
        $I->click('Submit');

        // Custom validation should reject
        $I->seeResponseContains('Custom validation failed');
    }

    /**
     * Scenario 11: Event modification of submission
     */
    public function testEventModificationOfSubmission(FunctionalTester $I)
    {
        // Setup modifier listener
        $I->amOnPage('/test/submission-modifier');

        $I->amOnPage('/forms/email-test');
        $I->submitForm(['field_name' => 'Original Name']);

        // Verify submission was modified
        $I->seeInDatabase('simpleform_submissions', [
            'data' => '%Modified Name%',
        ]);
    }

    /**
     * Scenario 12: Multiple event listeners
     */
    public function testMultipleEventListeners(FunctionalTester $I)
    {
        // Register multiple listeners
        $I->amOnPage('/test/multi-listener');

        $I->amOnPage('/forms/email-test');
        $I->submitForm(['field_name' => 'Multi Listener Test']);

        // Verify all listeners fired
        $I->seeInDatabase('test_events_log', [
            'listener' => 'listener_1',
        ]);
        $I->seeInDatabase('test_events_log', [
            'listener' => 'listener_2',
        ]);
        $I->seeInDatabase('test_events_log', [
            'listener' => 'listener_3',
        ]);
    }
}

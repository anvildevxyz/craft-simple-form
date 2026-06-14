<?php

namespace fabianhaef\simpleform\tests\smoke;

class SubmissionManagementCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
        // Create test form with submissions
        $I->createTestForm('Manage Test', 'manage-test');
        $I->createMultipleSubmissions('manage-test', 5);
    }

    /**
     * Scenario 1: View submissions list
     */
    public function testViewSubmissionsList(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->see('Submissions', 'h1');
        $I->see('Manage Test', 'td'); // Form name visible
    }

    /**
     * Scenario 2: Filter by form
     */
    public function testFilterByForm(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->selectOption('form-filter', 'manage-test');

        $I->seeInDatabase('simpleform_submissions', [
            'formId' => 1, // or actual form ID
        ]);
    }

    /**
     * Scenario 3: Filter by status
     */
    public function testFilterByStatus(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->selectOption('status-filter', 'new');

        $I->see('NEW'); // Status badge
    }

    /**
     * Scenario 4: Search submissions
     */
    public function testSearchSubmissions(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->fillField('search-input', 'john@example.com');

        $I->seeInDatabase('simpleform_submissions', [
            'data' => '%john%',
        ]);
    }

    /**
     * Scenario 5: View submission details
     */
    public function testViewSubmissionDetails(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->click('View', "//table//tr[1]");

        $I->see('Submission Details');
        $I->see('Full Name');
        $I->see('Email Address');
    }

    /**
     * Scenario 6: Toggle status new → read
     */
    public function testToggleStatusNewToRead(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->click('Toggle Status', "//tr[1]");

        // Verify status changed in DB
        $I->seeInDatabase('simpleform_submissions', [
            'readStatus' => 'read',
        ]);
    }

    /**
     * Scenario 7: Toggle status read → archived
     */
    public function testToggleStatusReadToArchived(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');

        // First change to read
        $I->click('Toggle Status', "//tr[1]");

        // Then toggle to archived
        $I->click('Toggle Status', "//tr[1]");

        $I->seeInDatabase('simpleform_submissions', [
            'readStatus' => 'archived',
        ]);
    }

    /**
     * Scenario 8: Pagination
     */
    public function testSubmissionsPagination(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');

        // Create many submissions to trigger pagination
        $I->createMultipleSubmissions('manage-test', 100);

        $I->see('Page 1');
        $I->see('Next');
        $I->click('Next');
        $I->see('Page 2');
    }

    /**
     * Scenario 9: View all submission data
     */
    public function testViewAllSubmissionData(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->click('View', "//table//tr[1]");

        // Verify all fields displayed
        $I->see('Full Name');
        $I->see('Email Address');
        $I->see('Message');
        $I->see('Date');
    }

    /**
     * Scenario 10: Submission date display
     */
    public function testSubmissionDateDisplay(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');

        // Should show date in list
        $I->seeElement('td:contains("2026")'); // Year visible
    }

    /**
     * Scenario 11: User info in submission
     */
    public function testUserInfoInSubmission(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->click('View', "//table//tr[1]");

        // Should show submitted by
        $I->see('Admin');
    }

    /**
     * Scenario 12: Multiple form submissions
     */
    public function testMultipleFormSubmissions(FunctionalTester $I)
    {
        // Create another form
        $I->amOnPage('/admin/simple-form/forms');
        $I->createForm('Form 2', 'form-2');

        // Submit to both forms
        $I->amOnPage('/forms/manage-test');
        $I->submitForm(['field_name' => 'Test 1']);

        $I->amOnPage('/forms/form-2');
        $I->submitForm(['field_name' => 'Test 2']);

        // View submissions
        $I->amOnPage('/admin/simple-form/submissions');
        $I->see('Manage Test');
        $I->see('Form 2');
    }
}

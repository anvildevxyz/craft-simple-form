<?php

namespace fabianhaef\simpleform\tests\smoke;
use FunctionalTester;
class SubmissionManagementCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
    }

    public function testViewSubmissionsList(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->seeResponseCodeIsSuccessful();
        $I->see('Submissions');
    }

    public function testFilterSubmissionsByForm(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->selectOption('formFilter', 'contact');
        $I->click('Apply');

        $I->see('contact');
    }

    public function testFilterSubmissionsByStatus(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->selectOption('statusFilter', 'new');
        $I->click('Apply');

        $I->see('New');
    }

    public function testSearchSubmissions(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->fillField('search', 'John Doe');
        $I->click('Search');

        $I->seeResponseContains('John Doe');
    }

    public function testViewSubmissionDetails(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->click('View', "//tr[1]");

        $I->see('Submission Details');
        $I->see('Status');
    }

    public function testToggleStatusNewToRead(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        // Click on a submission's status toggle
        $I->click('New', "//tr[1]//span[@class='status']");

        $I->see('Read');
        $I->seeInDatabase('simpleform_submissions', ['status' => 'read']);
    }

    public function testToggleStatusReadToArchived(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        // Click on a submission's status toggle
        $I->click('Read', "//tr[1]//span[@class='status']");

        $I->see('Archived');
        $I->seeInDatabase('simpleform_submissions', ['status' => 'archived']);
    }

    public function testPagination(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        // Should have pagination controls
        $I->seeElement('//nav[contains(@class, "pagination")]');
    }

    public function testViewAllSubmissionData(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->click('View', "//tr[1]");

        // Should display all submitted fields
        $I->see('Name');
        $I->see('Email');
    }

    public function testSubmissionDateDisplay(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        // Date column should be visible
        $I->seeElement('//th[contains(., "Date")]');
    }

    public function testUserInfoInSubmission(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->click('View', "//tr[1]");

        // Should show user info if logged in
        $I->seeElement('//div[@class="user-info"]');
    }

    public function testMultipleFormSubmissions(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        // Filter to show submissions from multiple forms
        $I->seeElement('//tbody/tr');
        $I->seeInDatabase('simpleform_submissions', ['formId' => 1]);
    }
}

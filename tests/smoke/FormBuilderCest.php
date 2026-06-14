<?php

namespace fabianhaef\simpleform\tests\smoke;
use FunctionalTester;
use craft\elements\User;
use Codeception\Util\HttpCode;

class FormBuilderCest
{
    public function loginAsAdmin(FunctionalTester $I)
    {
        $I->amOnPage('/admin');
        $I->fillField('loginName', 'admin');
        $I->fillField('password', 'password');
        $I->click('Sign in');
    }

    public function testCreateFormInCP(FunctionalTester $I)
    {
        $this->loginAsAdmin($I);

        // Navigate to forms
        $I->amOnPage('/admin/simple-form/forms');
        $I->see('Forms');

        // Create new form
        $I->click('New Form');
        $I->see('Create Form');

        // Fill form details
        $I->fillField('name', 'Contact Us');
        $I->fillField('handle', 'contact-us');
        $I->fillField('title', 'Contact Us Form');
        $I->fillField('description', 'Send us your feedback');
        $I->fillField('emailTo', 'test@example.com');
        $I->fillField('emailSubject', 'New Contact Submission');

        // Save form
        $I->click('Save');
        $I->see('Contact Us Form');
    }

    public function testAddFieldsToForm(FunctionalTester $I)
    {
        $this->loginAsAdmin($I);

        // Navigate to forms
        $I->amOnPage('/admin/simple-form/forms');

        // Find and edit first form
        $I->click('Contact Us Form');
        $I->see('Edit Form');

        // Add field stubs (would add actual field UI in full implementation)
        // This is a placeholder for the field management UI
    }

    public function testFormAppearsInCP(FunctionalTester $I)
    {
        $this->loginAsAdmin($I);

        $I->amOnPage('/admin/simple-form/forms');
        $I->see('Contact Us');
        $I->see('contact-us');
        $I->see('test@example.com');
    }
}

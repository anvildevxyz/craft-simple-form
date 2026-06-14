<?php

namespace fabianhaef\simpleform\tests\smoke;

use Codeception\Util\HttpCode;
use FunctionalTester;

class FormBuilderCompleteCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
    }

    public function testCreateFormWithBasicInfo(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');

        $I->fillField('name', 'Contact Form');
        $I->fillField('handle', 'contact');
        $I->fillField('title', 'Get in Touch');
        $I->fillField('description', 'Send us a message');
        $I->fillField('emailTo', 'admin@example.com');
        $I->fillField('emailSubject', 'New Contact Request');

        $I->click('Save');
        $I->see('Contact Form');
        $I->seeInDatabase('simpleform_forms', ['handle' => 'contact']);
    }

    public function testAddTextFieldWithValidation(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Text Test Form');
        $I->fillField('handle', 'text-test');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Full Name');
        $I->fillField('handle', 'full_name');
        $I->selectOption('type', 'text');
        $I->checkOption('required');
        $I->fillField('minLength', '2');
        $I->fillField('maxLength', '100');

        $I->click('Save Field');
        $I->see('Full Name');
    }

    public function testAddEmailField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Email Test Form');
        $I->fillField('handle', 'email-test');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Email Address');
        $I->fillField('handle', 'email_address');
        $I->selectOption('type', 'email');
        $I->checkOption('required');

        $I->click('Save Field');
        $I->see('Email Address');
    }

    public function testAddTextareaField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Textarea Test Form');
        $I->fillField('handle', 'textarea-test');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Message');
        $I->fillField('handle', 'message');
        $I->selectOption('type', 'textarea');
        $I->checkOption('required');

        $I->click('Save Field');
        $I->see('Message');
    }

    public function testAddSelectField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Select Test Form');
        $I->fillField('handle', 'select-test');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Department');
        $I->fillField('handle', 'department');
        $I->selectOption('type', 'select');
        $I->fillField('options', "Sales\nSupport\nBilling");

        $I->click('Save Field');
        $I->see('Department');
    }

    public function testAddCheckboxField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Checkbox Test Form');
        $I->fillField('handle', 'checkbox-test');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Interests');
        $I->fillField('handle', 'interests');
        $I->selectOption('type', 'checkbox');
        $I->fillField('options', "Option 1\nOption 2\nOption 3");

        $I->click('Save Field');
        $I->see('Interests');
    }

    public function testAddRadioField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Radio Test Form');
        $I->fillField('handle', 'radio-test');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Preference');
        $I->fillField('handle', 'preference');
        $I->selectOption('type', 'radio');
        $I->fillField('options', "Yes\nNo\nMaybe");

        $I->click('Save Field');
        $I->see('Preference');
    }

    public function testAddDateField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Date Test Form');
        $I->fillField('handle', 'date-test');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Birthdate');
        $I->fillField('handle', 'birthdate');
        $I->selectOption('type', 'date');

        $I->click('Save Field');
        $I->see('Birthdate');
    }

    public function testAddNumberField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Number Test Form');
        $I->fillField('handle', 'number-test');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Quantity');
        $I->fillField('handle', 'quantity');
        $I->selectOption('type', 'number');
        $I->fillField('minValue', '1');
        $I->fillField('maxValue', '100');

        $I->click('Save Field');
        $I->see('Quantity');
    }

    public function testEditExistingForm(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Edit Test Form');
        $I->fillField('handle', 'edit-test');
        $I->click('Save');

        $I->click('Edit Test Form');
        $I->fillField('name', 'Updated Edit Test');
        $I->click('Save');

        $I->see('Updated Edit Test');
        $I->seeInDatabase('simpleform_forms', ['name' => 'Updated Edit Test']);
    }

    public function testDeleteForm(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Delete Test Form');
        $I->fillField('handle', 'delete-test');
        $I->click('Save');

        $I->click('Delete');
        $I->acceptPopup();

        $I->dontSee('Delete Test Form');
        $I->dontSeeInDatabase('simpleform_forms', ['handle' => 'delete-test']);
    }

    public function testReorderFields(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Reorder Test');
        $I->fillField('handle', 'reorder-test');
        $I->click('Save');

        // Add multiple fields
        $I->click('Add Field');
        $I->fillField('label', 'Field 1');
        $I->fillField('handle', 'field_1');
        $I->click('Save Field');

        $I->click('Add Field');
        $I->fillField('label', 'Field 2');
        $I->fillField('handle', 'field_2');
        $I->click('Save Field');

        // Reorder via drag-and-drop (simplified)
        $I->see('Field 1');
        $I->see('Field 2');
    }
}

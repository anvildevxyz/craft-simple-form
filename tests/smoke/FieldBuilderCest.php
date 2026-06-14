<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;

class FieldBuilderCest
{
    private $formId;
    private $siteId;

    public function _before(FunctionalTester $I)
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        // Create a test form
        $form = new Form();
        $form->siteId = $this->siteId;
        $form->name = 'field-builder-test-' . uniqid();
        $form->handle = 'fieldBuilderTest' . uniqid();
        $form->title = 'Field Builder Test Form';

        Craft::$app->getElements()->saveElement($form);
        $this->formId = $form->id;
    }

    public function testAddTextField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->see('Add Field');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->fillField('field-label', 'Full Name');
        $I->waitForText('fullname', 2, '#field-handle'); // Auto-generated handle
        $I->fillField('field-minLength', '5');
        $I->fillField('field-maxLength', '100');

        $I->click('Save Field');
        $I->waitForText('Full Name', 5, '.matrixblock');
    }

    public function testAddEmailField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->selectOption('field-type', 'email');
        $I->fillField('field-label', 'Email Address');
        $I->click('Save Field');
        $I->waitForText('Email Address', 5, '.matrixblock');
    }

    public function testAddTextareaField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->selectOption('field-type', 'textarea');
        $I->fillField('field-label', 'Message');
        $I->fillField('field-textareaMinLength', '10');
        $I->fillField('field-textareaMaxLength', '500');

        $I->click('Save Field');
        $I->waitForText('Message', 5, '.matrixblock');
    }

    public function testAddSelectField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->selectOption('field-type', 'select');
        $I->fillField('field-label', 'Country');

        // Add options
        $I->fillField('field-select-options .option-label', 'United States');
        $I->fillField('field-select-options .option-value', 'us');

        $I->click('#add-select-option');
        $I->waitForElement('field-select-options .option-row:last-child', 3);

        $I->click('Save Field');
        $I->waitForText('Country', 5, '.matrixblock');
    }

    public function testAddCheckboxField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->selectOption('field-type', 'checkbox');
        $I->fillField('field-label', 'Interests');

        // Add options
        $I->fillField('field-checkbox-options .option-label', 'Sports');
        $I->fillField('field-checkbox-options .option-value', 'sports');

        $I->click('Save Field');
        $I->waitForText('Interests', 5, '.matrixblock');
    }

    public function testAddRadioField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->selectOption('field-type', 'radio');
        $I->fillField('field-label', 'Age Group');

        // Add options
        $I->fillField('field-radio-options .option-label', '18-25');
        $I->fillField('field-radio-options .option-value', '18-25');

        $I->click('Save Field');
        $I->waitForText('Age Group', 5, '.matrixblock');
    }

    public function testAddDateField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->selectOption('field-type', 'date');
        $I->fillField('field-label', 'Event Date');

        $I->click('Save Field');
        $I->waitForText('Event Date', 5, '.matrixblock');
    }

    public function testAddNumberField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->selectOption('field-type', 'number');
        $I->fillField('field-label', 'Quantity');
        $I->fillField('field-numberMin', '1');
        $I->fillField('field-numberMax', '999');

        $I->click('Save Field');
        $I->waitForText('Quantity', 5, '.matrixblock');
    }

    public function testHandleAutoGeneration(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->fillField('field-label', 'My Test Field');
        $I->waitForText('mytestfield', 2, '#field-handle');

        // Handle should be auto-generated
        $I->seeInField('field-handle', 'mytestfield');
    }

    public function testValidationErrors(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        // Try to save without label
        $I->click('Save Field');
        $I->see('Label is required');
    }

    public function testRequiredFieldCheckbox(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->fillField('field-label', 'Required Field');
        $I->checkOption('field-required');

        $I->click('Save Field');
        $I->waitForText('Required Field', 5, '.matrixblock');
    }

    public function testCreateFormWithMultipleFields(FunctionalTester $I)
    {
        // Add 5 fields to the form
        for ($i = 1; $i <= 5; $i++) {
            $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
            $I->click('Add Field');
            $I->waitForElement('#field-modal', 5);

            $label = 'Field ' . $i;
            $I->fillField('field-label', $label);
            $I->click('Save Field');
            $I->waitForText($label, 5, '.matrixblock');
        }

        // Verify all 5 fields are displayed
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        for ($i = 1; $i <= 5; $i++) {
            $I->see('Field ' . $i);
        }
    }

    public function testModalCancel(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        $I->fillField('field-label', 'Test Field');
        $I->click('Cancel');
        $I->waitForElementNotVisible('#field-modal-overlay.show', 3);

        // Field should not be added
        $I->dontSee('Test Field');
    }

    public function testFieldTypeConfigSections(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms/edit/' . $this->formId . '?site=default');
        $I->click('Add Field');
        $I->waitForElement('#field-modal', 5);

        // Text field should show min/max length
        $I->seeElement('#config-text');

        // Switch to select field
        $I->selectOption('field-type', 'select');
        $I->seeElement('#config-select');
        $I->dontSeeElement('#config-text');

        // Switch to date field
        $I->selectOption('field-type', 'date');
        $I->seeElement('#config-date');
        $I->dontSeeElement('#config-select');
    }
}

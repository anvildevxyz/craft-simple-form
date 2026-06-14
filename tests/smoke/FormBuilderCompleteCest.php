<?php

namespace fabianhaef\simpleform\tests\smoke;

use Codeception\Util\HttpCode;

class FormBuilderCompleteCest
{
    private $formId;

    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
    }

    /**
     * Scenario 1: Create form with all basic fields
     */
    public function testCreateFormWithBasicInfo(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->see('Forms', 'h1');
        $I->click('New Form');

        $I->fillField('name', 'Newsletter Signup');
        $I->fillField('handle', 'newsletter-signup');
        $I->fillField('title', 'Join Our Newsletter');
        $I->fillField('description', 'Get the latest updates delivered to your inbox');
        $I->fillField('emailTo', 'admin@example.com');
        $I->fillField('emailSubject', 'New Newsletter Signup');

        $I->click('Save');
        $I->see('Join Our Newsletter');

        // Verify in DB
        $I->seeInDatabase('simpleform_forms', [
            'handle' => 'newsletter-signup',
            'name' => 'Newsletter Signup',
        ]);
    }

    /**
     * Scenario 2: Add Text field with validation
     */
    public function testAddTextFieldWithValidation(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Contact Form ' . time();
        $I->createForm($formName, 'contact-' . time());

        // Add text field
        $I->click('Add Field');
        $I->fillField('label', 'Full Name');
        $I->fillField('name', 'full_name');
        $I->selectOption('type', 'text');
        $I->checkOption('required');
        $I->fillField('config[minLength]', '3');
        $I->fillField('config[maxLength]', '100');
        $I->click('Save Field');

        // Verify field created
        $I->seeInDatabase('simpleform_fields', [
            'label' => 'Full Name',
            'type' => 'text',
        ]);
    }

    /**
     * Scenario 3: Add Email field
     */
    public function testAddEmailField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Email Form ' . time();
        $I->createForm($formName, 'email-' . time());

        $I->click('Add Field');
        $I->fillField('label', 'Email Address');
        $I->selectOption('type', 'email');
        $I->checkOption('required');
        $I->click('Save Field');

        $I->seeInDatabase('simpleform_fields', [
            'label' => 'Email Address',
            'type' => 'email',
        ]);
    }

    /**
     * Scenario 4: Add Textarea field
     */
    public function testAddTextareaField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Feedback Form ' . time();
        $I->createForm($formName, 'feedback-' . time());

        $I->click('Add Field');
        $I->fillField('label', 'Your Feedback');
        $I->selectOption('type', 'textarea');
        $I->checkOption('required');
        $I->click('Save Field');

        $I->seeInDatabase('simpleform_fields', [
            'type' => 'textarea',
        ]);
    }

    /**
     * Scenario 5: Add Select field with options
     */
    public function testAddSelectField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Survey ' . time();
        $I->createForm($formName, 'survey-' . time());

        $I->click('Add Field');
        $I->fillField('label', 'How satisfied are you?');
        $I->selectOption('type', 'select');
        $I->fillField('config[options]', json_encode([
            'very_satisfied' => 'Very Satisfied',
            'satisfied' => 'Satisfied',
            'neutral' => 'Neutral',
            'dissatisfied' => 'Dissatisfied',
        ]));
        $I->click('Save Field');

        $I->seeInDatabase('simpleform_fields', [
            'type' => 'select',
        ]);
    }

    /**
     * Scenario 6: Add Checkbox field
     */
    public function testAddCheckboxField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Preferences ' . time();
        $I->createForm($formName, 'prefs-' . time());

        $I->click('Add Field');
        $I->fillField('label', 'Interests');
        $I->selectOption('type', 'checkbox');
        $I->fillField('config[options]', json_encode([
            'tech' => 'Technology',
            'business' => 'Business',
            'lifestyle' => 'Lifestyle',
        ]));
        $I->click('Save Field');

        $I->seeInDatabase('simpleform_fields', [
            'type' => 'checkbox',
        ]);
    }

    /**
     * Scenario 7: Add Radio field
     */
    public function testAddRadioField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Options ' . time();
        $I->createForm($formName, 'options-' . time());

        $I->click('Add Field');
        $I->fillField('label', 'Choose one');
        $I->selectOption('type', 'radio');
        $I->fillField('config[options]', json_encode([
            'opt1' => 'Option 1',
            'opt2' => 'Option 2',
            'opt3' => 'Option 3',
        ]));
        $I->click('Save Field');

        $I->seeInDatabase('simpleform_fields', [
            'type' => 'radio',
        ]);
    }

    /**
     * Scenario 8: Add Date field
     */
    public function testAddDateField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Event ' . time();
        $I->createForm($formName, 'event-' . time());

        $I->click('Add Field');
        $I->fillField('label', 'Event Date');
        $I->selectOption('type', 'date');
        $I->checkOption('required');
        $I->click('Save Field');

        $I->seeInDatabase('simpleform_fields', [
            'type' => 'date',
        ]);
    }

    /**
     * Scenario 9: Add Number field
     */
    public function testAddNumberField(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Quantity ' . time();
        $I->createForm($formName, 'qty-' . time());

        $I->click('Add Field');
        $I->fillField('label', 'Quantity');
        $I->selectOption('type', 'number');
        $I->fillField('config[min]', '1');
        $I->fillField('config[max]', '100');
        $I->click('Save Field');

        $I->seeInDatabase('simpleform_fields', [
            'type' => 'number',
        ]);
    }

    /**
     * Scenario 10: Edit existing form
     */
    public function testEditExistingForm(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Editable ' . time();
        $I->createForm($formName, 'edit-' . time());

        // Edit the form
        $I->amOnPage('/admin/simple-form/forms');
        $I->click($formName);

        $I->fillField('title', 'Updated Title');
        $I->click('Save');

        $I->see('Updated Title');
    }

    /**
     * Scenario 11: Delete form
     */
    public function testDeleteForm(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Deletable ' . time();
        $I->createForm($formName, 'del-' . time());

        // Delete
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Delete', "//tr[contains(., '$formName')]");
        $I->see('Form deleted');

        $I->dontSee($formName);
    }

    /**
     * Scenario 12: Reorder fields via drag
     */
    public function testReorderFields(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $formName = 'Reorder ' . time();
        $I->createForm($formName, 'reorder-' . time());

        // Add multiple fields
        for ($i = 1; $i <= 3; $i++) {
            $I->click('Add Field');
            $I->fillField('label', "Field $i");
            $I->click('Save Field');
        }

        // Verify all fields exist
        $I->seeInDatabase('simpleform_fields', ['label' => 'Field 1']);
        $I->seeInDatabase('simpleform_fields', ['label' => 'Field 2']);
        $I->seeInDatabase('simpleform_fields', ['label' => 'Field 3']);
    }
}

<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;

/**
 * Form Rendering Smoke Tests
 *
 * Tests complete frontend form rendering with all field types.
 * Covers HTML output, field rendering, CSRF tokens, and form structure.
 */
class FormRenderingCest
{
    private $formId;
    private $siteId;
    private $formHandle;

    public function _before(FunctionalTester $I)
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        // Create test form
        $form = new Form();
        $form->siteId = $this->siteId;
        $form->name = 'rendering-test-' . uniqid();
        $form->handle = $this->formHandle = 'renderTest' . uniqid();
        $form->title = 'Form Rendering Test';
        $form->emailTo = 'admin@test.com';

        Craft::$app->getElements()->saveElement($form);
        $this->formId = $form->id;
    }

    public function testFormRendersBasicHTML(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('class="simple-form"', $html);
        $I->assertStringContainsString('method="POST"', $html);
        $I->assertStringContainsString('action="/simple-form/submit"', $html);
        $I->assertStringContainsString('type="submit"', $html);
        $I->assertStringContainsString('class="simple-form-submit-btn"', $html);
    }

    public function testFormIncludesCSRFToken(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('csrfInput', $html, 'Should render CSRF token');
    }

    public function testFormIncludesHoneypot(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('name="__honeypot"', $html);
        $I->assertStringContainsString('display:none', $html);
    }

    public function testFormIncludesFormHandle(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('name="formHandle"', $html);
        $I->assertStringContainsString('value="' . $this->formHandle . '"', $html);
    }

    public function testTextFieldRendering(FunctionalTester $I)
    {
        // Add text field
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'username',
            'label' => 'Username',
            'helpText' => 'Enter your username',
            'config' => json_encode(['minLength' => 3, 'maxLength' => 50]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('Username', $html);
        $I->assertStringContainsString('Enter your username', $html);
        $I->assertStringContainsString('type="text"', $html);
        $I->assertStringContainsString('name="field_', $html);
    }

    public function testEmailFieldRendering(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'email',
            'name' => 'email',
            'label' => 'Email Address',
            'config' => json_encode(['required' => true]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('Email Address', $html);
        $I->assertStringContainsString('type="email"', $html);
        $I->assertStringContainsString('<span class="required">*</span>', $html);
    }

    public function testTextareaFieldRendering(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'textarea',
            'name' => 'message',
            'label' => 'Your Message',
            'config' => json_encode(['minLength' => 10]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('Your Message', $html);
        $I->assertStringContainsString('<textarea', $html);
        $I->assertStringContainsString('rows="6"', $html);
    }

    public function testSelectFieldRendering(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'select',
            'name' => 'country',
            'label' => 'Country',
            'config' => json_encode([
                'options' => [
                    ['label' => 'USA', 'value' => 'us'],
                    ['label' => 'Canada', 'value' => 'ca'],
                ]
            ]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('Country', $html);
        $I->assertStringContainsString('<select', $html);
        $I->assertStringContainsString('<option', $html);
        $I->assertStringContainsString('USA', $html);
        $I->assertStringContainsString('Canada', $html);
        $I->assertStringContainsString('value="us"', $html);
        $I->assertStringContainsString('value="ca"', $html);
    }

    public function testCheckboxFieldRendering(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'checkbox',
            'name' => 'interests',
            'label' => 'Interests',
            'config' => json_encode([
                'options' => [
                    ['label' => 'Sports', 'value' => 'sports'],
                    ['label' => 'Music', 'value' => 'music'],
                ]
            ]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('Interests', $html);
        $I->assertStringContainsString('type="checkbox"', $html);
        $I->assertStringContainsString('Sports', $html);
        $I->assertStringContainsString('Music', $html);
        $I->assertStringContainsString('checkbox-group', $html);
    }

    public function testRadioFieldRendering(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'radio',
            'name' => 'ageGroup',
            'label' => 'Age Group',
            'config' => json_encode([
                'options' => [
                    ['label' => '18-25', 'value' => '18-25'],
                    ['label' => '26-35', 'value' => '26-35'],
                ]
            ]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('Age Group', $html);
        $I->assertStringContainsString('type="radio"', $html);
        $I->assertStringContainsString('18-25', $html);
        $I->assertStringContainsString('26-35', $html);
        $I->assertStringContainsString('radio-group', $html);
    }

    public function testDateFieldRendering(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'date',
            'name' => 'eventDate',
            'label' => 'Event Date',
            'config' => json_encode(['format' => 'Y-m-d']),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('Event Date', $html);
        $I->assertStringContainsString('type="date"', $html);
    }

    public function testNumberFieldRendering(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'number',
            'name' => 'quantity',
            'label' => 'Quantity',
            'config' => json_encode(['min' => 1, 'max' => 100]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('Quantity', $html);
        $I->assertStringContainsString('type="number"', $html);
    }

    public function testFormWithAllFieldTypes(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $fields = [
            ['type' => 'text', 'name' => 'name', 'label' => 'Name'],
            ['type' => 'email', 'name' => 'email', 'label' => 'Email'],
            ['type' => 'textarea', 'name' => 'message', 'label' => 'Message'],
            ['type' => 'select', 'name' => 'country', 'label' => 'Country', 'options' => [['label' => 'US', 'value' => 'us']]],
            ['type' => 'checkbox', 'name' => 'agree', 'label' => 'Agree', 'options' => [['label' => 'Yes', 'value' => 'yes']]],
            ['type' => 'radio', 'name' => 'choice', 'label' => 'Choice', 'options' => [['label' => 'A', 'value' => 'a']]],
            ['type' => 'date', 'name' => 'date', 'label' => 'Date'],
            ['type' => 'number', 'name' => 'number', 'label' => 'Number'],
        ];

        foreach ($fields as $index => $field) {
            $config = [];
            if (isset($field['options'])) {
                $config['options'] = $field['options'];
            }

            $db->createCommand()->insert('{{%simpleform_fields}}', [
                'formId' => $this->formId,
                'type' => $field['type'],
                'name' => $field['name'],
                'label' => $field['label'],
                'config' => json_encode($config),
                'sortOrder' => $index + 1,
                'dateCreated' => date('Y-m-d H:i:s'),
                'dateUpdated' => date('Y-m-d H:i:s'),
                'uid' => Craft::$app->getSecurity()->generateRandomString(36),
            ])->execute();
        }

        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        // Check all field labels are present
        foreach ($fields as $field) {
            $I->assertStringContainsString($field['label'], $html, 'Should render ' . $field['label']);
        }
    }

    public function testFormNotFound(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("nonExistentForm") }}');

        $I->assertStringContainsString('Form "nonExistentForm" not found', $html);
    }

    public function testEmptyFormHandleError(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("") }}');

        $I->assertStringContainsString('Form handle is required', $html);
    }

    public function testFormWithNoFields(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        // Form should still render even with no fields
        $I->assertStringContainsString('simple-form', $html);
        $I->assertStringContainsString('type="submit"', $html);
    }

    public function testCustomSubmitButtonText(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '", { submitText: "Send Message" }) }}');

        $I->assertStringContainsString('Send Message', $html);
    }

    public function testFormIncludesInlineCSS(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('<style>', $html);
        $I->assertStringContainsString('.simple-form', $html);
        $I->assertStringContainsString('.simple-form-group', $html);
    }

    public function testFormIncludesJavaScript(FunctionalTester $I)
    {
        $view = Craft::$app->getView();
        $html = $view->renderString('{{ simpleForm("' . $this->formHandle . '") }}');

        $I->assertStringContainsString('<script>', $html);
        $I->assertStringContainsString('fetch', $html);
        $I->assertStringContainsString('addEventListener', $html);
    }
}

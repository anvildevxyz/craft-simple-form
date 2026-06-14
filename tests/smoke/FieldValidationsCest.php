<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;

/**
 * Field-Specific Validations Smoke Tests
 *
 * Tests validation rules for each field type including edge cases.
 */
class FieldValidationsCest
{
    private $formId;
    private $siteId;
    private $formHandle;

    public function _before(FunctionalTester $I)
    {
        $this->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $form = new Form();
        $form->siteId = $this->siteId;
        $form->name = 'validation-test-' . uniqid();
        $form->handle = $this->formHandle = 'validationTest' . uniqid();
        $form->title = 'Validation Test Form';
        $form->emailTo = 'admin@test.com';

        Craft::$app->getElements()->saveElement($form);
        $this->formId = $form->id;
    }

    public function testRequiredFieldValidation(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'name',
            'label' => 'Name',
            'config' => json_encode(['required' => true]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Empty submission should fail
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => '',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);
        $I->assertArrayHasKey('errors', $response);
        $I->assertContains('required', strtolower($response['errors']['field_' . $fieldId][0] ?? ''));
    }

    public function testTextMinLength(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'code',
            'label' => 'Code',
            'config' => json_encode(['minLength' => 5]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Too short - should fail
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'abc',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);

        // Exact minimum - should pass
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'abcde',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testTextMaxLength(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'shortcode',
            'label' => 'Short Code',
            'config' => json_encode(['maxLength' => 5]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Too long - should fail
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'toolongvalue',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);

        // Within limit - should pass
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'ok',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testEmailValidation(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'email',
            'name' => 'email',
            'label' => 'Email',
            'config' => json_encode(['required' => true]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        $invalidEmails = [
            'notanemail',
            'missing@domain',
            '@nodomain.com',
            'spaces in@email.com',
        ];

        foreach ($invalidEmails as $email) {
            $I->sendPost('/simple-form/submit', [
                'formHandle' => $this->formHandle,
                'field_' . $fieldId => $email,
            ]);
            $response = json_decode($I->grabPageSource(), true);
            $I->assertFalse($response['success'], "Email '$email' should fail validation");
        }

        $validEmails = [
            'user@example.com',
            'test.user@example.co.uk',
            'user+tag@example.com',
        ];

        foreach ($validEmails as $email) {
            $I->sendPost('/simple-form/submit', [
                'formHandle' => $this->formHandle,
                'field_' . $fieldId => $email,
            ]);
            $response = json_decode($I->grabPageSource(), true);
            $I->assertTrue($response['success'], "Email '$email' should pass validation");
        }
    }

    public function testTextareaMinLength(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'textarea',
            'name' => 'feedback',
            'label' => 'Feedback',
            'config' => json_encode(['minLength' => 20]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Too short - should fail
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'Short',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);

        // Long enough - should pass
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'This is a longer feedback message that exceeds minimum',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testSelectFieldRequiredValidation(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'select',
            'name' => 'country',
            'label' => 'Country',
            'config' => json_encode([
                'required' => true,
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
        $fieldId = $db->getLastInsertID();

        // No selection - should fail
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => '',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);

        // Valid selection - should pass
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'us',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testCheckboxFieldValidation(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'checkbox',
            'name' => 'terms',
            'label' => 'I agree',
            'config' => json_encode([
                'required' => true,
                'options' => [
                    ['label' => 'I agree to terms', 'value' => 'agree'],
                ]
            ]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // No selection - should fail
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);

        // Valid selection - should pass
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'agree',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testNumberFieldValidation(FunctionalTester $I)
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
        $fieldId = $db->getLastInsertID();

        // Valid number
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 50,
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);

        // Decimal number
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 42.5,
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testDateFieldValidation(FunctionalTester $I)
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
        $fieldId = $db->getLastInsertID();

        // Valid date
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => '2026-06-20',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);

        // Empty optional field - should pass
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => '',
        ]);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testMultipleValidationErrors(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();

        // Add multiple fields
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'name',
            'label' => 'Name',
            'config' => json_encode(['required' => true, 'minLength' => 3]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $nameFieldId = $db->getLastInsertID();

        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'email',
            'name' => 'email',
            'label' => 'Email',
            'config' => json_encode(['required' => true]),
            'sortOrder' => 2,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $emailFieldId = $db->getLastInsertID();

        // Submit with both errors
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $nameFieldId => 'ab',  // Too short
            'field_' . $emailFieldId => 'invalid',  // Invalid email
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);
        $I->assertCount(2, $response['errors']);
        $I->assertArrayHasKey('field_' . $nameFieldId, $response['errors']);
        $I->assertArrayHasKey('field_' . $emailFieldId, $response['errors']);
    }

    public function testOptionalFieldsCanBeEmpty(FunctionalTester $I)
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'notes',
            'label' => 'Additional Notes',
            'config' => json_encode([]),  // Not required
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Empty optional field - should pass
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => '',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }
}

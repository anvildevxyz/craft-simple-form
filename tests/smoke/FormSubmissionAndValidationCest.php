<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;

/**
 * Form Submission & Validation Smoke Tests
 *
 * Tests form submission, validation, error handling, and data persistence.
 */
class FormSubmissionAndValidationCest
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
        $form->name = 'submission-test-' . uniqid();
        $form->handle = $this->formHandle = 'submitTest' . uniqid();
        $form->title = 'Submission Test Form';
        $form->emailTo = 'admin@test.com';

        Craft::$app->getElements()->saveElement($form);
        $this->formId = $form->id;
    }

    public function testSubmitFormWithValidData(FunctionalTester $I)
    {
        // Add text field
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'name',
            'label' => 'Name',
            'config' => json_encode([]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Submit form
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'John Doe',
        ]);

        $I->seeResponseCodeIs(200);
        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success'], 'Form submission should succeed');
        $I->assertArrayHasKey('message', $response);

        // Verify submission was saved
        $submission = Submission::find()
            ->formId($this->formId)
            ->one();
        $I->assertNotNull($submission, 'Submission should be saved');
    }

    public function testSubmitWithMissingRequiredField(FunctionalTester $I)
    {
        // Add required field
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'email',
            'label' => 'Email',
            'config' => json_encode(['required' => true]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();

        // Submit without field
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success'], 'Should fail validation');
        $I->assertArrayHasKey('errors', $response);
    }

    public function testSubmitWithInvalidEmail(FunctionalTester $I)
    {
        // Add email field
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

        // Submit with invalid email
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'not-an-email',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);
        $I->assertArrayHasKey('errors', $response);
        $I->assertArrayHasKey('field_' . $fieldId, $response['errors']);
    }

    public function testSubmitWithTextLengthValidation(FunctionalTester $I)
    {
        // Add text field with min length
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'username',
            'label' => 'Username',
            'config' => json_encode(['minLength' => 5, 'maxLength' => 20]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Submit with too short value
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'abc',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);
        $I->assertArrayHasKey('errors', $response);

        // Submit with valid length
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'validusername',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testSubmitWithSelectFieldValidation(FunctionalTester $I)
    {
        // Add select field
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
        $fieldId = $db->getLastInsertID();

        // Submit with invalid option
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'invalid',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);

        // Submit with valid option
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'us',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testSubmitWithCheckboxField(FunctionalTester $I)
    {
        // Add checkbox field
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
        $fieldId = $db->getLastInsertID();

        // Submit with multiple selections
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => ['sports', 'music'],
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);

        // Verify data was saved
        $submission = Submission::find()
            ->formId($this->formId)
            ->one();
        $I->assertNotNull($submission);
    }

    public function testSubmitWithRadioField(FunctionalTester $I)
    {
        // Add radio field
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'radio',
            'name' => 'choice',
            'label' => 'Choose One',
            'config' => json_encode([
                'options' => [
                    ['label' => 'Option A', 'value' => 'a'],
                    ['label' => 'Option B', 'value' => 'b'],
                ]
            ]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Submit
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'a',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);
    }

    public function testHoneypotPreventsSpam(FunctionalTester $I)
    {
        // Submit with honeypot field filled
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            '__honeypot' => 'spam',
        ]);

        // Should redirect silently (honeypot protection)
        $I->seeResponseCodeIs(302);
    }

    public function testMissingFormHandle(FunctionalTester $I)
    {
        $I->sendPost('/simple-form/submit', []);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);
        $I->assertArrayHasKey('errors', $response);
    }

    public function testInvalidFormHandle(FunctionalTester $I)
    {
        $I->sendPost('/simple-form/submit', [
            'formHandle' => 'nonexistent',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertFalse($response['success']);
    }

    public function testSubmissionDataFormat(FunctionalTester $I)
    {
        // Add field
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'testfield',
            'label' => 'Test Field',
            'config' => json_encode([]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Submit
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'Test Value',
        ]);

        $response = json_decode($I->grabPageSource(), true);
        $I->assertTrue($response['success']);

        // Check saved data format
        $submission = Submission::find()
            ->formId($this->formId)
            ->one();

        $data = json_decode($submission->data, true);
        $I->assertArrayHasKey('field_' . $fieldId, $data);
        $I->assertArrayHasKey('label', $data['field_' . $fieldId]);
        $I->assertArrayHasKey('type', $data['field_' . $fieldId]);
        $I->assertArrayHasKey('value', $data['field_' . $fieldId]);
        $I->assertEquals('Test Field', $data['field_' . $fieldId]['label']);
        $I->assertEquals('Test Value', $data['field_' . $fieldId]['value']);
    }

    public function testMultipleSubmissions(FunctionalTester $I)
    {
        // Add field
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'name',
            'label' => 'Name',
            'config' => json_encode([]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Submit 3 times
        for ($i = 1; $i <= 3; $i++) {
            $I->sendPost('/simple-form/submit', [
                'formHandle' => $this->formHandle,
                'field_' . $fieldId => 'User ' . $i,
            ]);

            $response = json_decode($I->grabPageSource(), true);
            $I->assertTrue($response['success']);
        }

        // Verify all 3 submissions were saved
        $submissions = Submission::find()
            ->formId($this->formId)
            ->all();

        $I->assertCount(3, $submissions);
    }

    public function testSubmissionContainsCorrectFieldInfo(FunctionalTester $I)
    {
        // Add field with specific config
        $db = Craft::$app->getDb();
        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $this->formId,
            'type' => 'text',
            'name' => 'fullName',
            'label' => 'Full Name',
            'helpText' => 'Your complete name',
            'config' => json_encode(['minLength' => 5]),
            'sortOrder' => 1,
            'dateCreated' => date('Y-m-d H:i:s'),
            'dateUpdated' => date('Y-m-d H:i:s'),
            'uid' => Craft::$app->getSecurity()->generateRandomString(36),
        ])->execute();
        $fieldId = $db->getLastInsertID();

        // Submit
        $I->sendPost('/simple-form/submit', [
            'formHandle' => $this->formHandle,
            'field_' . $fieldId => 'John Smith',
        ]);

        // Check submission
        $submission = Submission::find()
            ->formId($this->formId)
            ->one();

        $I->assertEquals($this->formId, $submission->formId);
        $I->assertEquals('new', $submission->readStatus);

        $data = json_decode($submission->data, true);
        $fieldData = $data['field_' . $fieldId];
        $I->assertEquals('Full Name', $fieldData['label']);
        $I->assertEquals('text', $fieldData['type']);
        $I->assertEquals('John Smith', $fieldData['value']);
    }
}

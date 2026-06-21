<?php

namespace fabianhaef\simpleform\tests\smoke;

use fabianhaef\simpleform\elements\Submission;
use SmokeTester;

/**
 * Form Submission & Validation Smoke Tests (functional).
 *
 * Exercises the public submit path through the real shared entry point
 * {@see \fabianhaef\simpleform\services\SubmissionService::createFromRequest()}
 * — the same method the front-end SubmitController calls — seeding the form and
 * fields through the data layer (see {@see BaseSmokeCest}). Assertions are made
 * against the returned envelope and the persisted {@see Submission} element.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FormSubmissionAndValidationCest extends BaseSmokeCest
{
    // =========================================================================
    // PRIVATE PROPERTIES
    // =========================================================================

    private int $formId;

    private string $formHandle;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $form = $this->createForm('Submission Test Form', 'submitTest' . uniqid(), 'admin@test.com');
        $this->formId = (int)$form->id;
        $this->formHandle = $form->handle;
    }

    public function testSubmitFormWithValidData(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'text', 'name', 'Name');

        $result = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'John Doe']);

        $I->assertNull($result['errors'], 'Valid submission should not error');
        $I->assertInstanceOf(Submission::class, $result['submission']);

        $submission = Submission::find()->formId($this->formId)->one();
        $I->assertNotNull($submission, 'Submission should be saved');
    }

    public function testSubmitWithMissingRequiredField(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'text', 'email', 'Email', true);

        $result = $this->submitRequest($this->formHandle, []);

        $I->assertNull($result['submission'], 'Should fail validation');
        $I->assertNotNull($result['errors']);
        $I->assertArrayHasKey('field_' . $fieldId, $result['errors']);
    }

    public function testSubmitWithInvalidEmail(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'email', 'email', 'Email', true);

        $result = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'not-an-email']);

        $I->assertNull($result['submission']);
        $I->assertNotNull($result['errors']);
        $I->assertArrayHasKey('field_' . $fieldId, $result['errors']);
    }

    public function testSubmitWithTextLengthValidation(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'text', 'username', 'Username', false, [
            'minLength' => 5,
            'maxLength' => 20,
        ]);

        $tooShort = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'abc']);
        $I->assertNull($tooShort['submission']);
        $I->assertNotNull($tooShort['errors']);

        $valid = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'validusername']);
        $I->assertNull($valid['errors']);
        $I->assertInstanceOf(Submission::class, $valid['submission']);
    }

    public function testSubmitWithSelectFieldValidation(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'select', 'country', 'Country', false, [
            'options' => [
                ['label' => 'USA', 'value' => 'us'],
                ['label' => 'Canada', 'value' => 'ca'],
            ],
        ]);

        $invalid = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'invalid']);
        $I->assertNull($invalid['submission']);
        $I->assertNotNull($invalid['errors']);

        $valid = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'us']);
        $I->assertNull($valid['errors']);
        $I->assertInstanceOf(Submission::class, $valid['submission']);
    }

    public function testSubmitWithCheckboxField(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'checkbox', 'interests', 'Interests', false, [
            'options' => [
                ['label' => 'Sports', 'value' => 'sports'],
                ['label' => 'Music', 'value' => 'music'],
            ],
        ]);

        $result = $this->submitRequest($this->formHandle, ['field_' . $fieldId => ['sports', 'music']]);

        $I->assertNull($result['errors']);
        $I->assertInstanceOf(Submission::class, $result['submission']);

        $submission = Submission::find()->formId($this->formId)->one();
        $I->assertNotNull($submission);
    }

    public function testSubmitWithRadioField(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'radio', 'choice', 'Choose One', false, [
            'options' => [
                ['label' => 'Option A', 'value' => 'a'],
                ['label' => 'Option B', 'value' => 'b'],
            ],
        ]);

        $result = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'a']);

        $I->assertNull($result['errors']);
        $I->assertInstanceOf(Submission::class, $result['submission']);
    }

    public function testHoneypotPreventsSpam(SmokeTester $I): void
    {
        // A filled honeypot is dropped silently: no submission, no errors — bots
        // get no signal, and nothing is persisted.
        $before = Submission::find()->formId($this->formId)->status(null)->count();

        $result = $this->submitRequest($this->formHandle, ['__honeypot' => 'spam']);

        $I->assertNull($result['submission'], 'Honeypot hit must not persist a submission');
        $I->assertNull($result['errors'], 'Honeypot hit returns no errors (silent drop)');

        $after = Submission::find()->formId($this->formId)->status(null)->count();
        $I->assertSame($before, $after, 'No submission row stored for a honeypot hit');
    }

    public function testInvalidFormHandle(SmokeTester $I): void
    {
        $result = $this->service()->createFromRequest('nonexistent');

        $I->assertNull($result['submission']);
        $I->assertNotNull($result['errors']);
        $I->assertArrayHasKey('form', $result['errors']);
    }

    public function testSubmissionDataFormat(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'text', 'testfield', 'Test Field');

        $result = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'Test Value']);
        $I->assertInstanceOf(Submission::class, $result['submission']);

        $submission = Submission::find()->formId($this->formId)->one();
        $data = $submission->data;

        $I->assertArrayHasKey('field_' . $fieldId, $data);
        $I->assertArrayHasKey('label', $data['field_' . $fieldId]);
        $I->assertArrayHasKey('type', $data['field_' . $fieldId]);
        $I->assertArrayHasKey('value', $data['field_' . $fieldId]);
        $I->assertSame('Test Field', $data['field_' . $fieldId]['label']);
        $I->assertSame('Test Value', $data['field_' . $fieldId]['value']);
    }

    public function testMultipleSubmissions(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'text', 'name', 'Name');

        for ($i = 1; $i <= 3; $i++) {
            $result = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'User ' . $i]);
            $I->assertInstanceOf(Submission::class, $result['submission']);
        }

        $submissions = Submission::find()->formId($this->formId)->all();
        $I->assertCount(3, $submissions);
    }

    public function testSubmissionContainsCorrectFieldInfo(SmokeTester $I): void
    {
        $fieldId = $this->createField($this->formId, 'text', 'fullName', 'Full Name', false, [
            'minLength' => 5,
        ], 'Your complete name');

        $result = $this->submitRequest($this->formHandle, ['field_' . $fieldId => 'John Smith']);
        $I->assertInstanceOf(Submission::class, $result['submission']);

        $submission = Submission::find()->formId($this->formId)->one();

        $I->assertSame($this->formId, $submission->formId);
        $I->assertSame('new', $submission->readStatus);

        $fieldData = $submission->data['field_' . $fieldId];
        $I->assertSame('Full Name', $fieldData['label']);
        $I->assertSame('text', $fieldData['type']);
        $I->assertSame('John Smith', $fieldData['value']);
    }
}

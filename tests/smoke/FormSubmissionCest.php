<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use SmokeTester;

/**
 * Form Submission Smoke Tests (functional).
 *
 * Exercises the end-to-end submit flow — seed a multi-field form, post through
 * the shared {@see \anvildev\simpleform\services\SubmissionService::createFromRequest()}
 * entry point, then assert the persisted {@see Submission} carries the submitted
 * values for each field type. Forms and fields are seeded through the data layer
 * (see {@see BaseSmokeCest}).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FormSubmissionCest extends BaseSmokeCest
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
        $form = $this->createForm('Submission Test Form', 'submitFlow' . uniqid(), 'admin@test.com');
        $this->formId = (int)$form->id;
        $this->formHandle = $form->handle;
    }

    public function testSubmitFormWithValidData(SmokeTester $I): void
    {
        $nameId = $this->createField($this->formId, 'text', 'name', 'Name', true);
        $emailId = $this->createField($this->formId, 'email', 'email', 'Email', true);
        $messageId = $this->createField($this->formId, 'textarea', 'message', 'Message');

        $result = $this->submitRequest($this->formHandle, [
            'field_' . $nameId => 'John Doe',
            'field_' . $emailId => 'john@example.com',
            'field_' . $messageId => 'Test message',
        ]);

        $I->assertNull($result['errors']);
        $I->assertInstanceOf(Submission::class, $result['submission']);

        $submission = Submission::find()->formId($this->formId)->one();
        $I->assertSame('John Doe', $submission->data['field_' . $nameId]['value']);
        $I->assertSame('john@example.com', $submission->data['field_' . $emailId]['value']);
    }

    public function testSubmitFormWithInvalidEmail(SmokeTester $I): void
    {
        $nameId = $this->createField($this->formId, 'text', 'name', 'Name', true);
        $emailId = $this->createField($this->formId, 'email', 'email', 'Email', true);

        $result = $this->submitRequest($this->formHandle, [
            'field_' . $nameId => 'Jane Doe',
            'field_' . $emailId => 'invalid-email',
        ]);

        $I->assertNull($result['submission']);
        $I->assertNotNull($result['errors']);
        $I->assertArrayHasKey('field_' . $emailId, $result['errors']);
    }

    public function testSubmitWithMissingRequiredField(SmokeTester $I): void
    {
        $nameId = $this->createField($this->formId, 'text', 'name', 'Name', true);

        $result = $this->submitRequest($this->formHandle, ['field_' . $nameId => '']);

        $I->assertNull($result['submission']);
        $I->assertArrayHasKey('field_' . $nameId, $result['errors']);
    }

    public function testSelectFieldValuePersists(SmokeTester $I): void
    {
        $statusId = $this->createField($this->formId, 'select', 'status', 'Status', false, [
            'options' => [
                ['label' => 'Active', 'value' => 'active'],
                ['label' => 'Inactive', 'value' => 'inactive'],
            ],
        ]);

        $result = $this->submitRequest($this->formHandle, ['field_' . $statusId => 'active']);

        $I->assertNull($result['errors']);
        $submission = Submission::find()->formId($this->formId)->one();
        $I->assertSame('active', $submission->data['field_' . $statusId]['value']);
    }

    public function testCheckboxMultipleValuesPersist(SmokeTester $I): void
    {
        $interestsId = $this->createField($this->formId, 'checkbox', 'interests', 'Interests', false, [
            'options' => [
                ['label' => 'Sports', 'value' => 'sports'],
                ['label' => 'Music', 'value' => 'music'],
                ['label' => 'Reading', 'value' => 'reading'],
            ],
        ]);

        $result = $this->submitRequest($this->formHandle, ['field_' . $interestsId => ['sports', 'music']]);

        $I->assertNull($result['errors']);
        $submission = Submission::find()->formId($this->formId)->one();
        $I->assertContains('sports', $submission->data['field_' . $interestsId]['value']);
        $I->assertContains('music', $submission->data['field_' . $interestsId]['value']);
    }

    public function testNumberFieldMinMaxValidation(SmokeTester $I): void
    {
        $quantityId = $this->createField($this->formId, 'number', 'quantity', 'Quantity', false, [
            'min' => 1,
            'max' => 100,
        ]);

        $tooHigh = $this->submitRequest($this->formHandle, ['field_' . $quantityId => '150']);
        $I->assertNull($tooHigh['submission']);
        $I->assertArrayHasKey('field_' . $quantityId, $tooHigh['errors']);

        $valid = $this->submitRequest($this->formHandle, ['field_' . $quantityId => '42']);
        $I->assertNull($valid['errors']);
        $I->assertInstanceOf(Submission::class, $valid['submission']);
    }

    public function testDateFieldValuePersists(SmokeTester $I): void
    {
        $birthdateId = $this->createField($this->formId, 'date', 'birthdate', 'Birthdate');

        $result = $this->submitRequest($this->formHandle, ['field_' . $birthdateId => '1990-01-15']);

        $I->assertNull($result['errors']);
        $submission = Submission::find()->formId($this->formId)->one();
        $I->assertStringContainsString('1990', (string)$submission->data['field_' . $birthdateId]['value']);
    }
}

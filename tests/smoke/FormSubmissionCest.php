<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SubmissionCsv;
use Craft;
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

    /**
     * Query-string prefill (#316): an opted-in field is pre-filled from its URL
     * query param (default: the field handle), while a non-opted field ignores
     * the query entirely — so an arbitrary param can never inject a value.
     */
    public function testQueryStringPrefillOnlyOptedInFields(SmokeTester $I): void
    {
        $nameId = $this->createField($this->formId, 'text', 'name', 'Name', true, [
            'prefillFromQuery' => true,
        ]);
        $this->createField($this->formId, 'email', 'email', 'Email', true);

        Craft::$app->getRequest()->setQueryParams([
            'name' => 'Prefilled Name',
            'email' => 'sneaky@example.com',
        ]);

        $html = $this->renderForm($this->formHandle);

        // Opted-in field carries the query value on its own input.
        $I->assertMatchesRegularExpression(
            '/id="field_' . $nameId . '"[^>]*value="Prefilled Name"/',
            $html,
        );
        // Non-opted field ignores the query param — its value never appears.
        $I->assertStringNotContainsString('sneaky@example.com', $html);
    }

    /**
     * Query-string prefill honors a custom param name and the form-level default,
     * with a per-field Off override winning over that default.
     */
    public function testQueryStringPrefillCustomParamAndFormDefault(SmokeTester $I): void
    {
        $form = $this->getForm();
        $form->prefillFromQuery = true;
        Craft::$app->getElements()->saveElement($form);

        // Inherits the form-level default (no explicit flag), custom param name.
        $cityId = $this->createField($this->formId, 'text', 'city', 'City', false, [
            'prefillParam' => 'loc',
        ]);
        // Explicit per-field Off overrides the form-level default.
        $this->createField($this->formId, 'text', 'ref', 'Ref', false, [
            'prefillFromQuery' => false,
        ]);

        Craft::$app->getRequest()->setQueryParams([
            'loc' => 'Zurich',
            'ref' => 'blocked',
        ]);

        $html = $this->renderForm($this->formHandle);

        $I->assertMatchesRegularExpression(
            '/id="field_' . $cityId . '"[^>]*value="Zurich"/',
            $html,
        );
        $I->assertStringNotContainsString('blocked', $html);
    }

    /**
     * An array query param targeting a scalar field must never crash the
     * render (code-review fix for #316). Before the fix, `sanitizeValue()`
     * coerced any array query param into a `list<string>` and handed it
     * straight to the scalar field's renderer, which casts `(string) $value`
     * and throws "Array to string conversion" — a visitor loading a plain
     * `?<handle>[]=x` URL took the whole public form offline. The field must
     * now render un-prefilled instead of crashing the page.
     */
    public function testQueryStringPrefillRejectsArrayForScalarField(SmokeTester $I): void
    {
        $nameId = $this->createField($this->formId, 'text', 'name', 'Name', true, [
            'prefillFromQuery' => true,
        ]);

        Craft::$app->getRequest()->setQueryParams([
            'name' => ['x', 'y'],
        ]);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('id="field_' . $nameId . '"', $html);
        $I->assertStringNotContainsString('Array', $html);
        $I->assertDoesNotMatchRegularExpression(
            '/id="field_' . $nameId . '"[^>]*value="/',
            $html,
        );
    }

    /**
     * The form under test, freshly loaded through the element query.
     */
    private function getForm(): Form
    {
        return Form::find()->id($this->formId)->one();
    }

    /**
     * Field snapshots (#312): a submission records the form's field structure at
     * submit time, so renaming AND deleting fields afterward does not corrupt the
     * snapshot itself — handles, labels, option labels, and display order stay put.
     */
    public function testSnapshotCapturedAtSubmitTime(SmokeTester $I): void
    {
        $nameId = $this->createField($this->formId, 'text', 'name', 'Full Name', true);
        $topicId = $this->createField($this->formId, 'select', 'topic', 'Topic', false, [
            'options' => [
                ['label' => 'Sales', 'value' => 'sales'],
                ['label' => 'Support', 'value' => 'support'],
            ],
        ]);
        $messageId = $this->createField($this->formId, 'textarea', 'message', 'Message');

        $result = $this->submitRequest($this->formHandle, [
            'field_' . $nameId => 'Ada Lovelace',
            'field_' . $topicId => 'sales',
            'field_' . $messageId => 'Hello there',
        ]);
        $I->assertNull($result['errors']);

        // Rename one field and delete another AFTER the submission is stored.
        $this->renameField($nameId, 'Renamed Name');
        $this->deleteField($messageId);

        $submission = Submission::find()->formId($this->formId)->id($result['submission']->id)->one();
        $snapshot = $submission->fieldSnapshot;

        $I->assertIsArray($snapshot);
        // Handles + original labels + option labels are preserved.
        $I->assertSame('name', $snapshot['field_' . $nameId]['handle']);
        $I->assertSame('Full Name', $snapshot['field_' . $nameId]['label']);
        $I->assertSame('Message', $snapshot['field_' . $messageId]['label']);
        $I->assertSame('Sales', $snapshot['field_' . $topicId]['options']['sales']);
        // Display order is captured in field order.
        $I->assertSame(0, $snapshot['field_' . $nameId]['order']);
        $I->assertSame(1, $snapshot['field_' . $topicId]['order']);
        $I->assertSame(2, $snapshot['field_' . $messageId]['order']);
    }

    /**
     * Field snapshots (#312): the detail view (via {@see Submission::getDisplayData()})
     * and the CSV export both render the ORIGINAL labels and order after a live
     * field is renamed and another deleted.
     */
    public function testDetailAndExportUseSnapshotAfterFormChanges(SmokeTester $I): void
    {
        $nameId = $this->createField($this->formId, 'text', 'name', 'Full Name', true);
        $topicId = $this->createField($this->formId, 'text', 'topic', 'Topic');
        $messageId = $this->createField($this->formId, 'textarea', 'message', 'Message');

        $result = $this->submitRequest($this->formHandle, [
            'field_' . $nameId => 'Ada Lovelace',
            'field_' . $topicId => 'Billing',
            'field_' . $messageId => 'Hello there',
        ]);
        $I->assertNull($result['errors']);

        // Rename the first field and delete the last one.
        $this->renameField($nameId, 'Renamed Name');
        $this->deleteField($messageId);

        $submission = Submission::find()->formId($this->formId)->id($result['submission']->id)->one();

        // Detail view source: labels are the originals, in the original order.
        $display = $submission->getDisplayData();
        $I->assertSame(
            ['Full Name', 'Topic', 'Message'],
            array_values(array_map(static fn(array $e): string => $e['label'], $display)),
        );

        // CSV export: original headers present, renamed header absent.
        $csv = SubmissionCsv::fromSubmissions([$submission]);
        $I->assertStringContainsString('Full Name', $csv);
        $I->assertStringContainsString('Message', $csv);
        $I->assertStringNotContainsString('Renamed Name', $csv);
        // Column order follows the snapshot (Full Name before Message).
        $I->assertLessThan(strpos($csv, 'Message'), strpos($csv, 'Full Name'));
        // Values survive too.
        $I->assertStringContainsString('Ada Lovelace', $csv);
        $I->assertStringContainsString('Hello there', $csv);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Rename a field's per-site label, simulating an editor renaming it in the
     * builder after a submission already exists.
     */
    private function renameField(int $fieldId, string $label): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_fields_sites}}', ['label' => $label], ['fieldId' => $fieldId])
            ->execute();
    }

    /**
     * Delete a field outright (structural row + per-site rows), simulating an
     * editor removing it after a submission already exists.
     */
    private function deleteField(int $fieldId): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete('{{%simpleform_fields_sites}}', ['fieldId' => $fieldId])->execute();
        $db->createCommand()->delete('{{%simpleform_fields}}', ['id' => $fieldId])->execute();
    }
}

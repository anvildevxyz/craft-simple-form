<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;
use craft\db\Query;

/**
 * Exercises the real submission entry point (SubmissionService::createFromRequest,
 * the same path the SubmitController uses) and asserts the custom submission row
 * actually round-trips from the DB — this is the regression that the missing
 * Submission::afterSave() caused.
 *
 * @group requires-craft
 */
class SubmissionCreateAndValidationTest extends SimpleFormTestCase
{
    public function testValidSubmissionPersistsAndRoundTrips(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Feedback', 'feedbackForm', 'Feedback');
        $nameFieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);
        $emailFieldId = $this->createField($form->id, 'email', 'email', 'Email', true);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'feedbackForm',
            'field_' . $nameFieldId => 'Ada Lovelace',
            'field_' . $emailFieldId => 'ada@example.com',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $submissionId = $result['submission']->id;
        $this->assertNotNull($submissionId);

        // (a) The custom row must exist in the plugin table (afterSave wrote it).
        $row = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $submissionId])
            ->one();

        $this->assertNotFalse($row, 'simpleform_submissions row should exist for the saved element');
        $this->assertSame((int) $form->id, (int) $row['formId']);
        $this->assertSame('new', $row['readStatus']);

        $decoded = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);
        $this->assertSame('Ada Lovelace', $decoded['field_' . $nameFieldId]['value']);
        $this->assertSame('ada@example.com', $decoded['field_' . $emailFieldId]['value']);

        // (b) The element query (INNER joins simpleform_submissions) must find it
        //     and rehydrate the custom columns.
        $reloaded = Submission::find()->id($submissionId)->one();
        $this->assertNotNull($reloaded, 'Submission element query should return the saved submission');
        $this->assertSame((int) $form->id, $reloaded->formId);
        $this->assertSame('new', $reloaded->readStatus);
        $this->assertSame('Ada Lovelace', $reloaded->data['field_' . $nameFieldId]['value']);
    }

    public function testInvalidSubmissionFailsValidationAndStoresNothing(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Required', 'requiredForm', 'Required');
        $requiredFieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'requiredForm',
            // Required field left blank -> validation must fail.
            'field_' . $requiredFieldId => '',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertNotNull($result['errors']);
        $this->assertArrayHasKey('field_' . $requiredFieldId, $result['errors']);

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after, 'No submission row should be stored when validation fails');
    }

    public function testPerSiteOverrideReplacesDefaultValidationMessage(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Override', 'overrideErrForm', 'Override');
        // Seed a per-site custom error message for the required field.
        $fieldId = $this->createField(
            $form->id,
            'text',
            'fullName',
            'Full Name',
            true,
            [],
            null,
            '',
            'Please tell us your name.',
        );

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'overrideErrForm',
            'field_' . $fieldId => '',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNotNull($result['errors']);
        // The editor's wording replaces the field type's default message.
        $this->assertSame(['Please tell us your name.'], $result['errors']['field_' . $fieldId]);
    }

    public function testDefaultRequiredMessageIsLocalizedToActiveLanguage(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Localized', 'localizedErrForm', 'Localized');
        // No per-site override -> the field type's default must localize.
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'localizedErrForm',
            'field_' . $fieldId => '',
        ]);

        $original = Craft::$app->language;
        Craft::$app->language = 'de';
        try {
            $result = $this->submissionService()->createFromRequest($form, $request);
        } finally {
            Craft::$app->language = $original;
        }

        $this->assertNotNull($result['errors']);
        $this->assertSame(['Dieses Feld ist erforderlich.'], $result['errors']['field_' . $fieldId]);
    }

    public function testUpdateStatusRoundTrips(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Status', 'statusForm', 'Status');
        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'statusForm',
            'field_' . $fieldId => 'hello',
        ]);

        $service = $this->submissionService();
        $result = $service->createFromRequest($form, $request);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $this->assertTrue($service->updateStatus($result['submission']->id, 'read'));

        $reloaded = Submission::find()->id($result['submission']->id)->one();
        $this->assertSame('read', $reloaded->readStatus);
    }

    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }
}

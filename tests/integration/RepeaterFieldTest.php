<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\FieldSyncService;
use anvildev\simpleform\services\SubmissionService;
use Craft;
use craft\db\Query;

/**
 * End-to-end coverage for the Repeater field (issue #132): nested submission
 * persists as an ordered array of row objects, row-count bounds + per-cell rules
 * are enforced server-side, unknown inner keys are stripped, and the save-time
 * config validator rejects bad inner config. These boot a real Craft so the
 * Craft::t-bearing paths (validate(), repeaterConfigErrors) run with the
 * translation catalogs loaded.
 *
 * @group requires-craft
 */
class RepeaterFieldTest extends SimpleFormTestCase
{
    /**
     * Seed an Attendees repeater: Name (text, required) + Email (email,
     * required), min/max configurable. Returns the field id.
     */
    private function seedAttendees(int $formId, int $min, int $max): int
    {
        return $this->createField($formId, 'repeater', 'attendees', 'Attendees', false, [
            'minRows' => $min,
            'maxRows' => $max,
            'fields' => [
                ['handle' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
                ['handle' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
            ],
        ]);
    }

    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }

    public function testTwoRowsPersistAsArrayOfRowObjects(): void
    {
        $this->requireCraft();

        $form = $this->createForm('RSVP', 'rsvpForm', 'RSVP');
        $fieldId = $this->seedAttendees($form->id, 1, 3);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'rsvpForm',
            'field_' . $fieldId => [
                ['name' => 'Ada', 'email' => 'ada@example.com'],
                ['name' => 'Alan', 'email' => 'alan@example.com'],
            ],
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $row = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $result['submission']->id])
            ->one();
        $decoded = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);
        $value = $decoded['field_' . $fieldId]['value'];

        $this->assertSame('repeater', $decoded['field_' . $fieldId]['type']);
        $this->assertCount(2, $value);
        $this->assertSame(['name' => 'Ada', 'email' => 'ada@example.com'], $value[0]);
        $this->assertSame(['name' => 'Alan', 'email' => 'alan@example.com'], $value[1]);
    }

    public function testUnderMinRowsIsRejected(): void
    {
        $this->requireCraft();

        $form = $this->createForm('RSVP Min', 'rsvpMinForm', 'RSVP Min');
        $fieldId = $this->seedAttendees($form->id, 1, 3);

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'rsvpMinForm',
            'field_' . $fieldId => [], // zero rows, min 1
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('field_' . $fieldId, $result['errors']);

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after);
    }

    public function testOverMaxRowsIsRejected(): void
    {
        $this->requireCraft();

        $form = $this->createForm('RSVP Max', 'rsvpMaxForm', 'RSVP Max');
        $fieldId = $this->seedAttendees($form->id, 1, 2);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'rsvpMaxForm',
            'field_' . $fieldId => [
                ['name' => 'A', 'email' => 'a@x.io'],
                ['name' => 'B', 'email' => 'b@x.io'],
                ['name' => 'C', 'email' => 'c@x.io'],
            ],
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('field_' . $fieldId, $result['errors']);
    }

    public function testInvalidCellMapsToErrorAndPersistsNothing(): void
    {
        $this->requireCraft();

        $form = $this->createForm('RSVP Bad', 'rsvpBadForm', 'RSVP Bad');
        $fieldId = $this->seedAttendees($form->id, 1, 3);

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'rsvpBadForm',
            'field_' . $fieldId => [
                ['name' => 'Ada', 'email' => 'not-an-email'],
            ],
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $errors = $result['errors']['field_' . $fieldId];
        $this->assertNotEmpty($errors);
        // The message locates the failing cell (row + inner label).
        $this->assertStringContainsString('Row 1', $errors[0]);

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after);
    }

    public function testUnknownInnerKeysAreStripped(): void
    {
        $this->requireCraft();

        $form = $this->createForm('RSVP Strip', 'rsvpStripForm', 'RSVP Strip');
        $fieldId = $this->seedAttendees($form->id, 1, 3);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'rsvpStripForm',
            'field_' . $fieldId => [
                ['name' => 'Ada', 'email' => 'ada@x.io', 'isAdmin' => '1', 'evil' => 'x'],
            ],
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $value = $result['submission']->data['field_' . $fieldId]['value'];
        $this->assertSame(['name', 'email'], array_keys($value[0]));
    }

    public function testSaveTimeConfigValidatorRejectsBadInnerConfig(): void
    {
        $this->requireCraft();

        $sync = new FieldSyncService();

        // Disallowed inner type + duplicate handle + min > max + select w/o options.
        $errors = $sync->validate([
            [
                'label' => 'Bad Repeater',
                'handle' => 'badRepeater',
                'type' => 'repeater',
                'config' => [
                    'minRows' => 5,
                    'maxRows' => 2,
                    'fields' => [
                        ['handle' => 'a', 'type' => 'text', 'label' => 'A'],
                        ['handle' => 'a', 'type' => 'email', 'label' => 'Dup'],
                        ['handle' => 'doc', 'type' => 'file', 'label' => 'Doc'],
                        ['handle' => 'size', 'type' => 'select', 'label' => 'Size'],
                    ],
                ],
            ],
        ]);

        $joined = implode("\n", $errors);
        $this->assertStringContainsString('minimum rows cannot exceed maximum rows', $joined);
        $this->assertStringContainsString('duplicate inner handle', $joined);
        $this->assertStringContainsString('unsupported type', $joined);
        $this->assertStringContainsString('needs at least one option', $joined);
    }

    public function testValidConfigPassesSaveValidation(): void
    {
        $this->requireCraft();

        $sync = new FieldSyncService();

        $errors = $sync->validate([
            [
                'label' => 'Attendees',
                'handle' => 'attendees',
                'type' => 'repeater',
                'config' => [
                    'minRows' => 1,
                    'maxRows' => 3,
                    'fields' => [
                        ['handle' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true],
                        ['handle' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true],
                    ],
                ],
            ],
        ]);

        $this->assertSame([], $errors);
    }

    public function testRenderInputEmitsTemplateAndRows(): void
    {
        $this->requireCraft();

        $repeater = new \anvildev\simpleform\fields\RepeaterFieldType([
            'minRows' => 2,
            'maxRows' => 4,
            'fields' => [
                ['handle' => 'name', 'type' => 'text', 'label' => 'Name'],
            ],
        ]);

        $html = $repeater->renderInput('field_99', null);

        $this->assertStringContainsString('data-sf-repeater', $html);
        $this->assertStringContainsString('data-sf-min="2"', $html);
        $this->assertStringContainsString('data-sf-max="4"', $html);
        $this->assertStringContainsString('data-sf-repeater-template', $html);
        $this->assertStringContainsString('field_99[__INDEX__][name]', $html);
        // The prototype + max(1, minRows) = 2 rendered rows each carry the row
        // marker, so 3 total (1 template + 2 visible rows on first load).
        $this->assertSame(3, substr_count($html, 'data-sf-repeater-row>'));
        // The two visible rows index from 0.
        $this->assertStringContainsString('field_99[0][name]', $html);
        $this->assertStringContainsString('field_99[1][name]', $html);
    }
}

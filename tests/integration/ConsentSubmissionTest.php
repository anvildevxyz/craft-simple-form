<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\SubmissionCsv;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * #125 — the Consent field's server-side gate and auditable consent record,
 * exercised through the real shared submit path (the same SubmissionService both
 * the front-end controller and the GraphQL mutation route through). Confirms a
 * missing tick is rejected with no row, and a ticked box persists the record
 * with a server-stamped timestamp + text snapshot, and exports human-readably.
 *
 * @group requires-craft
 */
class ConsentSubmissionTest extends SimpleFormTestCase
{
    private const TEXT = 'I agree to the [privacy policy](https://example.com/privacy)';

    public function testUncheckedRequiredConsentIsRejectedServerSideWithNoRow(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Consent', 'consentForm', 'Consent');
        $fieldId = $this->createField($form->id, 'consent', 'consent', 'Consent', true, ['consentText' => self::TEXT]);

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'consentForm',
            // No field_<id> posted -> the box was not ticked.
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertNotNull($result['errors']);
        $this->assertArrayHasKey('field_' . $fieldId, $result['errors']);

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after, 'No submission row should be stored when consent is missing');
    }

    public function testForgedFalseyConsentValueIsRejected(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Consent2', 'consentForm2', 'Consent');
        $fieldId = $this->createField($form->id, 'consent', 'consent', 'Consent', true, ['consentText' => self::TEXT]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'consentForm2',
            // A forged non-truthy value must not satisfy the gate.
            'field_' . $fieldId => '0',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('field_' . $fieldId, $result['errors'] ?? []);
    }

    public function testCheckedConsentStoresAuditRecordWithServerTimestamp(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Consent3', 'consentForm3', 'Consent');
        $fieldId = $this->createField($form->id, 'consent', 'consent', 'Consent', true, ['consentText' => self::TEXT]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'consentForm3',
            'field_' . $fieldId => '1',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $reloaded = Submission::find()->id($result['submission']->id)->one();
        $record = $reloaded->data['field_' . $fieldId]['value'];

        $this->assertTrue($record['consented']);
        $this->assertNotEmpty($record['consentedAt']);
        // Server-stamped: a parseable instant within the last minute.
        $stamped = new \DateTimeImmutable($record['consentedAt']);
        $this->assertLessThan(60, abs(time() - $stamped->getTimestamp()));
        $this->assertSame('I agree to the privacy policy (https://example.com/privacy)', $record['textVersion']);
        $this->assertStringStartsWith('sha256:', $record['textHash']);
    }

    public function testDefaultRequiredMessageIsLocalized(): void
    {
        $this->requireCraft();

        $form = $this->createForm('ConsentLoc', 'consentLocForm', 'Consent');
        $fieldId = $this->createField($form->id, 'consent', 'consent', 'Consent', true, ['consentText' => self::TEXT]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => 'consentLocForm']);

        $original = Craft::$app->language;
        Craft::$app->language = 'de';
        try {
            $result = $this->submissionService()->createFromRequest($form, $request);
        } finally {
            Craft::$app->language = $original;
        }

        $this->assertNotNull($result['errors']);
        $this->assertSame(['Sie müssen zustimmen, bevor Sie absenden.'], $result['errors']['field_' . $fieldId]);
    }

    public function testConsentExportsHumanReadably(): void
    {
        $this->requireCraft();

        $form = $this->createForm('ConsentCsv', 'consentCsvForm', 'Consent');
        $fieldId = $this->createField($form->id, 'consent', 'consent', 'Consent', true, ['consentText' => self::TEXT]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'consentCsvForm',
            'field_' . $fieldId => '1',
        ]);
        $submission = $this->submissionService()->createFromRequest($form, $request)['submission'];
        $this->assertInstanceOf(Submission::class, $submission);

        $rows = SubmissionCsv::toRows([Submission::find()->id($submission->id)->one()]);
        $this->assertStringStartsWith('Yes (', $rows[0]['Consent']);
    }

    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }
}

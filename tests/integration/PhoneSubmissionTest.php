<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\fields\PhoneFieldType;
use anvildev\simpleform\helpers\SubmissionCsv;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;
use craft\db\Query;

/**
 * End-to-end coverage for the Phone field (#123) through the real submission
 * path: server-authoritative validation, `{raw, e164, country}` normalization on
 * persist, the translated country selector render, and the normalized export
 * cell. Boots a real Craft so `Craft::t` resolves the catalogs.
 *
 * @group requires-craft
 */
class PhoneSubmissionTest extends SimpleFormTestCase
{
    public function testValidPhoneNormalizesAndPersistsE164(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Contact', 'phoneContactForm', 'Contact');
        $fieldId = $this->createField($form->id, 'phone', 'phone', 'Phone', true, [
            'showCountrySelector' => true,
            'defaultCountry' => 'CH',
            'allowedCountries' => ['CH', 'DE'],
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'phoneContactForm',
            'field_' . $fieldId => ['country' => 'CH', 'number' => '079 123 45 67'],
        ]);

        $result = $this->service()->createFromRequest($form, $request);

        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $reloaded = Submission::find()->id($result['submission']->id)->one();
        $stored = $reloaded->data['field_' . $fieldId]['value'];

        $this->assertSame('+41791234567', $stored['e164']);
        $this->assertSame('CH', $stored['country']);
        $this->assertSame('079 123 45 67', $stored['raw']);
    }

    public function testFlatStringNormalizesAgainstDefaultCountry(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Callback', 'phoneFlatForm', 'Callback');
        // Selector hidden -> the input posts a flat string under field_<id>.
        $fieldId = $this->createField($form->id, 'phone', 'phone', 'Phone', true, [
            'showCountrySelector' => false,
            'defaultCountry' => 'DE',
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'phoneFlatForm',
            'field_' . $fieldId => '030 1234567',
        ]);

        $result = $this->service()->createFromRequest($form, $request);

        $this->assertNull($result['errors']);
        $stored = $result['submission']->data['field_' . $fieldId]['value'];
        $this->assertSame('+49301234567', $stored['e164']);
        $this->assertSame('DE', $stored['country']);
    }

    public function testInvalidPhoneFailsAndStoresNothing(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Invalid', 'phoneInvalidForm', 'Invalid');
        $fieldId = $this->createField($form->id, 'phone', 'phone', 'Phone', true, [
            'showCountrySelector' => true,
            'defaultCountry' => 'CH',
        ]);

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'phoneInvalidForm',
            'field_' . $fieldId => ['country' => 'CH', 'number' => 'abc'],
        ]);

        $result = $this->service()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertNotNull($result['errors']);
        $this->assertArrayHasKey('field_' . $fieldId, $result['errors']);
        $this->assertSame(
            ['Enter a valid phone number.'],
            $result['errors']['field_' . $fieldId],
        );

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after, 'No row should persist for an invalid phone number');
    }

    public function testRequiredEmptyPhoneFails(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Required', 'phoneRequiredForm', 'Required');
        $fieldId = $this->createField($form->id, 'phone', 'phone', 'Phone', true, [
            'showCountrySelector' => true,
            'defaultCountry' => 'CH',
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'phoneRequiredForm',
            'field_' . $fieldId => ['country' => 'CH', 'number' => ''],
        ]);

        $result = $this->service()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertSame(
            ['This field is required.'],
            $result['errors']['field_' . $fieldId],
        );
    }

    public function testDisallowedCountryFails(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Allowed', 'phoneAllowedForm', 'Allowed');
        $fieldId = $this->createField($form->id, 'phone', 'phone', 'Phone', true, [
            'showCountrySelector' => true,
            'defaultCountry' => 'CH',
            'allowedCountries' => ['CH', 'DE'],
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'phoneAllowedForm',
            'field_' . $fieldId => ['country' => 'US', 'number' => '212 555 0100'],
        ]);

        $result = $this->service()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertContains(
            'Please select a valid country.',
            $result['errors']['field_' . $fieldId],
        );
    }

    public function testSelectorRenderLimitsAndTranslatesCountries(): void
    {
        $this->requireCraft();

        $field = new PhoneFieldType([
            'showCountrySelector' => true,
            'defaultCountry' => 'CH',
            'allowedCountries' => ['CH', 'DE'],
        ]);
        $html = $field->renderInput('field_9');

        $this->assertStringContainsString('<select', $html);
        $this->assertStringContainsString('name="field_9[country]"', $html);
        $this->assertStringContainsString('name="field_9[number]"', $html);
        $this->assertStringContainsString('value="CH" data-dial="+41" selected', $html);
        $this->assertStringContainsString('value="DE" data-dial="+49"', $html);
        $this->assertStringNotContainsString('value="US"', $html);
        $this->assertStringContainsString('type="tel"', $html);
    }

    public function testExportShowsNormalizedNumber(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Export', 'phoneExportForm', 'Export');
        $fieldId = $this->createField($form->id, 'phone', 'phone', 'Phone', true, [
            'showCountrySelector' => true,
            'defaultCountry' => 'CH',
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'phoneExportForm',
            'field_' . $fieldId => ['country' => 'CH', 'number' => '079 123 45 67'],
        ]);

        $result = $this->service()->createFromRequest($form, $request);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $csv = SubmissionCsv::fromSubmissions([$result['submission']]);
        $this->assertStringContainsString('+41791234567', $csv);
        // The structured {raw, e164, country} map must not leak into the cell.
        $this->assertStringNotContainsString('079 123 45 67|', $csv);
    }

    private function service(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }
}

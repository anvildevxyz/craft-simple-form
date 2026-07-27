<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use craft\db\Query;
use SmokeTester;

/**
 * Extended field-type smoke tests (functional).
 *
 * Covers hidden, consent, phone, rating, and opinion-scale fields through the
 * public submit request path.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class FieldTypesSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    private const CONSENT_TEXT = 'I agree to the privacy policy';

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testStaticHiddenFieldPersists(SmokeTester $I): void
    {
        $form = $this->createForm('Hidden', 'hidden' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'hidden', 'campaign', 'Campaign', false, [
            'source' => 'query',
            'queryParam' => 'utm_source',
        ]);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => 'spring-sale']);

        $I->assertNull($result['errors']);
        $submission = Submission::find()->id($result['submission']->id)->one();
        $I->assertSame('spring-sale', $submission->data['field_' . $fieldId]['value']);
    }

    public function testRequiredConsentRejectedWhenUnchecked(SmokeTester $I): void
    {
        $form = $this->createForm('Consent', 'consent' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'consent', 'consent', 'Consent', true, [
            'consentText' => self::CONSENT_TEXT,
        ]);

        $before = (int) (new Query())->from('{{%simpleform_submissions}}')->count();
        $result = $this->submitRequest($form->handle, []);
        $after = (int) (new Query())->from('{{%simpleform_submissions}}')->count();

        $I->assertNull($result['submission']);
        $I->assertArrayHasKey('field_' . $fieldId, $result['errors']);
        $I->assertSame($before, $after);
    }

    public function testCheckedConsentStoresAuditRecord(SmokeTester $I): void
    {
        $form = $this->createForm('Consent OK', 'consentOk' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'consent', 'consent', 'Consent', true, [
            'consentText' => self::CONSENT_TEXT,
        ]);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => '1']);

        $I->assertNull($result['errors']);
        $value = $result['submission']->data['field_' . $fieldId]['value'];
        $I->assertTrue($value['consented']);
        $I->assertNotEmpty($value['consentedAt']);
        $I->assertSame(self::CONSENT_TEXT, $value['textVersion']);
    }

    public function testTimeFieldNormalizesAndStores(SmokeTester $I): void
    {
        $form = $this->createForm('Meeting', 'meeting' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'time', 'startsAt', 'Starts At', true);

        // A seconds-carrying value is normalized to the canonical HH:MM shape.
        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => '09:30:00']);

        $I->assertNull($result['errors']);
        $I->assertSame('09:30', $result['submission']->data['field_' . $fieldId]['value']);
    }

    public function testInvalidTimeIsRejected(SmokeTester $I): void
    {
        $form = $this->createForm('Meeting Reject', 'meetingReject' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'time', 'startsAt', 'Starts At', true);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => '25:99']);

        $I->assertNull($result['submission']);
        $I->assertArrayHasKey('field_' . $fieldId, $result['errors']);
    }

    public function testDateTimeFieldNormalizesAndStores(SmokeTester $I): void
    {
        $form = $this->createForm('Appointment', 'appointment' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'datetime', 'meetsAt', 'Meets At', true);

        // A seconds-carrying combined value keeps the date half and normalizes
        // the time half to the canonical HH:MM shape.
        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => '2026-07-09T09:30:00']);

        $I->assertNull($result['errors']);
        $I->assertSame('2026-07-09T09:30', $result['submission']->data['field_' . $fieldId]['value']);
    }

    public function testInvalidDateTimeIsRejected(SmokeTester $I): void
    {
        $form = $this->createForm('Appointment Reject', 'appointmentReject' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'datetime', 'meetsAt', 'Meets At', true);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => '2026-07-09T25:99']);

        $I->assertNull($result['submission']);
        $I->assertArrayHasKey('field_' . $fieldId, $result['errors']);
    }

    public function testUrlFieldNormalizesAndStores(SmokeTester $I): void
    {
        $form = $this->createForm('Website', 'website' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'url', 'website', 'Website', true);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => 'example.com']);

        $I->assertNull($result['errors']);
        // A scheme-less entry is normalized to https:// before storage.
        $I->assertSame('https://example.com', $result['submission']->data['field_' . $fieldId]['value']);
    }

    public function testInvalidUrlIsRejected(SmokeTester $I): void
    {
        $form = $this->createForm('Website Reject', 'websiteReject' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'url', 'website', 'Website', true);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => 'not a url']);

        $I->assertNull($result['submission']);
        $I->assertArrayHasKey('field_' . $fieldId, $result['errors']);
    }

    public function testPhoneFieldNormalizesToE164(SmokeTester $I): void
    {
        $form = $this->createForm('Phone', 'phone' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'phone', 'phone', 'Phone', true, [
            'showCountrySelector' => true,
            'defaultCountry' => 'CH',
            'allowedCountries' => ['CH'],
        ]);

        $result = $this->submitRequest($form->handle, [
            'field_' . $fieldId => ['country' => 'CH', 'number' => '079 123 45 67'],
        ]);

        $I->assertNull($result['errors']);
        $stored = $result['submission']->data['field_' . $fieldId]['value'];
        $I->assertSame('+41791234567', $stored['e164']);
        $I->assertSame('CH', $stored['country']);
    }

    public function testRatingValueStoredAsInteger(SmokeTester $I): void
    {
        $form = $this->createForm('Rating', 'rating' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'rating', 'stars', 'Stars', true, ['max' => 5]);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => '4']);

        $I->assertNull($result['errors']);
        $I->assertSame(4, $result['submission']->data['field_' . $fieldId]['value']);
    }

    public function testOutOfRangeRatingIsRejected(SmokeTester $I): void
    {
        $form = $this->createForm('Rating Reject', 'ratingReject' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'rating', 'stars', 'Stars', true, ['max' => 5]);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => '9']);

        $I->assertNull($result['submission']);
        $I->assertArrayHasKey('field_' . $fieldId, $result['errors']);
    }

    public function testOpinionScaleValueStoredAsInteger(SmokeTester $I): void
    {
        $form = $this->createForm('NPS', 'nps' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'opinion', 'recommend', 'Recommend', true, [
            'min' => 0,
            'max' => 10,
        ]);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => '9']);

        $I->assertNull($result['errors']);
        $I->assertSame(9, $result['submission']->data['field_' . $fieldId]['value']);
    }
}

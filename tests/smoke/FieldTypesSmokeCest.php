<?php

namespace fabianhaef\simpleform\tests\smoke;

use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use SmokeTester;

/**
 * Extended field-type smoke tests (functional).
 *
 * Covers hidden, consent, phone, rating, and opinion-scale fields through the
 * public submit request path.
 *
 * @author Fabian Haefliger
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

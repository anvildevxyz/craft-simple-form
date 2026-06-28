<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\helpers\SubmissionCsv;
use Craft;
use SmokeTester;

/**
 * UTM/referrer auto-capture smoke tests (#249): a real submit records the
 * attribution, and it surfaces in the CSV export.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class AttributionCaptureSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testAttributionCapturedOnSubmit(SmokeTester $I): void
    {
        $form = $this->attributionForm('attrSmoke' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $result = $this->submitRequest($form->handle, [
            'field_' . $fieldId => 'Ada',
            '__sf_attr' => [
                'utm_source' => 'newsletter',
                'utm_medium' => 'email',
                'utm_campaign' => 'spring',
            ],
        ]);

        $I->assertNull($result['errors']);
        $attr = $result['submission']->attribution;
        $I->assertIsArray($attr);
        $I->assertSame('newsletter', $attr['utm_source']);
        $I->assertSame('email', $attr['utm_medium']);
        $I->assertSame('spring', $attr['utm_campaign']);
    }

    public function testAttributionColumnsAppearInCsvExport(SmokeTester $I): void
    {
        $form = $this->attributionForm('attrCsv' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $result = $this->submitRequest($form->handle, [
            'field_' . $fieldId => 'Ada',
            '__sf_attr' => ['utm_source' => 'google', 'utm_medium' => 'cpc'],
        ]);

        $csv = SubmissionCsv::fromSubmissions([$result['submission']]);

        $I->assertStringContainsString('UTM Source', $csv);
        $I->assertStringContainsString('UTM Medium', $csv);
        $I->assertStringContainsString('google', $csv);
        $I->assertStringContainsString('cpc', $csv);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function attributionForm(string $handle): Form
    {
        $form = $this->createForm('Lead', $handle);
        $form->autoCaptureAttribution = true;
        Craft::$app->getElements()->saveElement($form);

        return $form;
    }
}

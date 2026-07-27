<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SubmissionCsv;
use SmokeTester;

/**
 * Presentational layout blocks on a real render/submit pass: the Callout (#322)
 * and Text/Paragraph (#264) blocks — plus Heading and Divider — render their
 * markup in source order, contribute no submission value, and are excluded from
 * the CSV export.
 *
 * Exercises the public render path through {@see BaseSmokeCest::renderForm()}
 * (the service the `simpleForm()` Twig function delegates to) and the submit path
 * through the shared submission service.
 *
 * @author Anvil Dev
 * @since 2.17.0
 */
class LayoutBlocksRenderCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * A form interleaving a real input with a heading, paragraph, callout and
     * divider renders every block's markup, in the authored order.
     */
    public function testLayoutBlocksRenderInSourceOrder(SmokeTester $I): void
    {
        [$handle, $nameId] = $this->seedInterleavedForm();

        $html = $this->renderForm($handle);

        // Each block's distinctive markup is present.
        $I->assertStringContainsString('<h2 class="simple-form-heading">Your Details</h2>', $html, 'the heading renders at its level');
        $I->assertStringContainsString('<div class="simple-form-text">', $html, 'the paragraph/text block renders');
        $I->assertStringContainsString('Please read carefully.', $html, 'the paragraph body copy is present');
        $I->assertStringContainsString('simple-form-callout simple-form-callout--info', $html, 'the callout renders with its tone');
        $I->assertStringContainsString('Bring your ID.', $html, 'the callout body copy is present');
        $I->assertStringContainsString('simple-form-divider__label', $html, 'the labelled divider renders');
        $I->assertStringContainsString('Or continue below', $html, 'the divider label is present');

        // Source order: heading -> paragraph -> name input -> callout -> divider.
        $posHeading = strpos($html, 'simple-form-heading');
        $posParagraph = strpos($html, 'simple-form-text');
        $posName = strpos($html, 'name="field_' . $nameId . '"');
        $posCallout = strpos($html, 'simple-form-callout--info');
        $posDivider = strpos($html, 'simple-form-divider__label');

        $I->assertLessThan($posParagraph, $posHeading, 'heading precedes the paragraph');
        $I->assertLessThan($posName, $posParagraph, 'paragraph precedes the name input');
        $I->assertLessThan($posCallout, $posName, 'name input precedes the callout');
        $I->assertLessThan($posDivider, $posCallout, 'callout precedes the divider');
    }

    /**
     * Submitting the form stores a value only for the real input — every layout
     * block is skipped, even when a value is forged against it.
     */
    public function testLayoutBlocksStoreNoSubmissionValue(SmokeTester $I): void
    {
        [$handle, $nameId, $ids] = $this->seedInterleavedForm(true);

        $result = $this->submitRequest($handle, [
            'field_' . $nameId => 'Ada',
            // Forge a value against every layout block — all must be ignored.
            'field_' . $ids['heading'] => 'forged',
            'field_' . $ids['paragraph'] => 'forged',
            'field_' . $ids['callout'] => 'forged',
            'field_' . $ids['divider'] => 'forged',
        ]);

        $I->assertNull($result['errors']);
        $submission = $result['submission'];
        $I->assertNotNull($submission);

        $I->assertSame(['field_' . $nameId], array_keys($submission->data), 'only the real input is stored');
        foreach ($submission->data as $entry) {
            $I->assertNotContains($entry['type'], ['heading', 'paragraph', 'callout', 'divider'], 'no layout block leaves an entry');
        }
    }

    /**
     * The CSV export has a column only for the real input; no layout block's
     * label or body copy appears anywhere in the export.
     */
    public function testLayoutBlocksExcludedFromCsvExport(SmokeTester $I): void
    {
        [$handle, $nameId] = $this->seedInterleavedForm();

        $this->submitRequest($handle, ['field_' . $nameId => 'Ada']);
        $submissions = Submission::find()->formId($this->formIdForHandle($handle))->all();

        $csv = SubmissionCsv::fromSubmissions($submissions);
        $header = explode("\n", trim($csv))[0];

        $I->assertStringContainsString('Full Name', $header, 'the real input has a column');
        $I->assertStringNotContainsString('Your Details', $csv, 'the heading text is not exported');
        $I->assertStringNotContainsString('Please read carefully.', $csv, 'the paragraph body is not exported');
        $I->assertStringNotContainsString('Bring your ID.', $csv, 'the callout body is not exported');
        $I->assertStringNotContainsString('Or continue below', $csv, 'the divider label is not exported');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * A form interleaving a heading, paragraph, a real text input, a callout and
     * a divider. Returns [handle, nameFieldId] or, when $withIds, additionally the
     * per-block field ids for forging posted values.
     *
     * @return array{0: string, 1: int}|array{0: string, 1: int, 2: array<string, int>}
     */
    private function seedInterleavedForm(bool $withIds = false): array
    {
        $form = $this->createForm('Layout', 'layoutRender' . uniqid());
        $formId = (int) $form->id;

        // Layout blocks carry their translatable body in the label/helpText
        // columns, threaded into config by the render service:
        //  - heading text  <- label
        //  - divider label <- label
        //  - paragraph body / callout body <- helpText
        $headingId = $this->createField($formId, 'heading', 'yourDetails', 'Your Details', false, ['level' => 'h2']);
        $paragraphId = $this->createField($formId, 'paragraph', 'intro', '', false, [], 'Please read carefully.');
        $nameId = $this->createField($formId, 'text', 'name', 'Full Name');
        $calloutId = $this->createField($formId, 'callout', 'tip', '', false, ['tone' => 'info'], 'Bring your ID.');
        $dividerId = $this->createField($formId, 'divider', 'sep', 'Or continue below');

        if ($withIds) {
            return [$form->handle, $nameId, [
                'heading' => $headingId,
                'paragraph' => $paragraphId,
                'callout' => $calloutId,
                'divider' => $dividerId,
            ]];
        }

        return [$form->handle, $nameId];
    }

    private function formIdForHandle(string $handle): int
    {
        return (int) \anvildev\simpleform\elements\Form::find()->handle($handle)->one()->id;
    }
}

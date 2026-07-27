<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\exporters\SubmissionExporter;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SubmissionCsv;
use SmokeTester;

/**
 * CSV column-selection smoke tests (#317): a real submit, then export a chosen
 * subset of columns and assert only those columns appear — while the
 * formula-injection neutralization (#CWE-1236) still applies to every cell.
 *
 * Selection is keyed by {@see SubmissionCsv::availableColumns()}'s stable
 * column key, not the display label — two fields can share a label (e.g. two
 * fields both "Comments") and must still be selectable independently (#328).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class CsvColumnSelectionSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testFromSubmissionsEmitsOnlySelectedColumns(SmokeTester $I): void
    {
        [$submission, $nameId] = $this->seed();

        // A subset: keep the metadata ID and the Name field, drop Email.
        $csv = SubmissionCsv::fromSubmissions([$submission], ['meta:id', $this->fieldKey($nameId)]);

        $I->assertStringContainsString('Full Name', $csv);
        $I->assertStringNotContainsString('Email Address', $csv);
        $I->assertStringNotContainsString('grace@example.test', $csv);

        // The formula-injection value survives as a neutralized cell.
        $I->assertStringContainsString("'=1+1", $csv);
    }

    public function testDefaultExportKeepsEveryColumn(SmokeTester $I): void
    {
        [$submission] = $this->seed();

        // No selection (null) — behaviour is unchanged, every column present.
        $csv = SubmissionCsv::fromSubmissions([$submission]);

        $I->assertStringContainsString('Full Name', $csv);
        $I->assertStringContainsString('Email Address', $csv);
        $I->assertStringContainsString('grace@example.test', $csv);
    }

    public function testAvailableColumnsListsEveryHeader(SmokeTester $I): void
    {
        [$submission] = $this->seed();

        $columns = SubmissionCsv::availableColumns([$submission]);
        $labels = array_column($columns, 'label');

        $I->assertContains('ID', $labels);
        $I->assertContains('Full Name', $labels);
        $I->assertContains('Email Address', $labels);
    }

    public function testExporterHonorsColumnSelection(SmokeTester $I): void
    {
        [$submission, $nameId] = $this->seed();

        $exporter = new SubmissionExporter();
        $exporter->columns = ['meta:id', $this->fieldKey($nameId)];
        $rows = $exporter->export(Submission::find()->id((int) $submission->id));

        $I->assertNotEmpty($rows);
        $I->assertArrayHasKey('Full Name', $rows[0]);
        $I->assertArrayNotHasKey('Email Address', $rows[0]);
        // Neutralization is intact on the emitted cell.
        $I->assertSame("'=1+1", $rows[0]['Full Name']);
    }

    /**
     * Two fields sharing the exact same label ("Comments") must remain
     * independently selectable: choosing one keeps only that column's value,
     * never both (#328 — selection matched by label, not a stable key, made
     * same-labeled columns indistinguishable).
     */
    public function testSelectingOneOfTwoSameLabeledColumnsKeepsOnlyThatOne(SmokeTester $I): void
    {
        $form = $this->createForm('Export Dup Labels', 'csvColsDup' . uniqid());
        $commentsAId = $this->createField((int) $form->id, 'text', 'commentsA', 'Comments');
        $commentsBId = $this->createField((int) $form->id, 'text', 'commentsB', 'Comments');

        $result = $this->submitRequest($form->handle, [
            'field_' . $commentsAId => 'alpha-marker',
            'field_' . $commentsBId => 'bravo-marker',
        ]);
        $submission = $result['submission'];

        // The picker's descriptors carry two distinct keys for the same label.
        $descriptors = SubmissionCsv::availableColumns([$submission]);
        $commentsKeys = array_values(array_filter(array_map(
            static fn(array $d): ?string => $d['label'] === 'Comments' ? $d['key'] : null,
            $descriptors,
        )));
        $I->assertCount(2, $commentsKeys, 'Expected two distinct "Comments" column keys.');
        $I->assertNotSame($commentsKeys[0], $commentsKeys[1]);

        // Select only the first "Comments" column (plus ID, so the header isn't empty).
        $csv = SubmissionCsv::fromSubmissions([$submission], ['meta:id', $commentsKeys[0]]);

        // Exactly one "Comments" header column survives the selection — the
        // second same-labeled column was neither kept nor dropped by mistake.
        $header = strtok($csv, "\n");
        $I->assertSame(1, substr_count((string) $header, 'Comments'));

        // Only the selected field's value made it into the export.
        $I->assertStringContainsString('alpha-marker', $csv);
        $I->assertStringNotContainsString('bravo-marker', $csv);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * The stable column key for a plain (non-composite) field column, matching
     * {@see SubmissionCsv}'s `field:<dataKey>` scheme for the submitted data
     * key `field_<fieldId>`.
     */
    private function fieldKey(int $fieldId): string
    {
        return 'field:field_' . $fieldId;
    }

    /**
     * Seed a form with two fields and one submission whose Name carries a
     * spreadsheet-formula payload (to prove neutralization survives selection).
     *
     * @return array{0: \anvildev\simpleform\elements\Submission, 1: int, 2: int}
     */
    private function seed(): array
    {
        $form = $this->createForm('Export', 'csvCols' . uniqid());
        $nameId = $this->createField((int) $form->id, 'text', 'name', 'Full Name');
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email Address');

        $result = $this->submitRequest($form->handle, [
            'field_' . $nameId => '=1+1',
            'field_' . $emailId => 'grace@example.test',
        ]);

        return [$result['submission'], $nameId, $emailId];
    }
}

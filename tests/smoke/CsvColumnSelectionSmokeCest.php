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
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class CsvColumnSelectionSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testFromSubmissionsEmitsOnlySelectedColumns(SmokeTester $I): void
    {
        [$submission] = $this->seed();

        // A subset: keep the metadata ID and the Name field, drop Email.
        $csv = SubmissionCsv::fromSubmissions([$submission], ['ID', 'Full Name']);

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

        $I->assertContains('ID', $columns);
        $I->assertContains('Full Name', $columns);
        $I->assertContains('Email Address', $columns);
    }

    public function testExporterHonorsColumnSelection(SmokeTester $I): void
    {
        [$submission] = $this->seed();

        $exporter = new SubmissionExporter();
        $exporter->columns = ['ID', 'Full Name'];
        $rows = $exporter->export(Submission::find()->id((int) $submission->id));

        $I->assertNotEmpty($rows);
        $I->assertArrayHasKey('Full Name', $rows[0]);
        $I->assertArrayNotHasKey('Email Address', $rows[0]);
        // Neutralization is intact on the emitted cell.
        $I->assertSame("'=1+1", $rows[0]['Full Name']);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Seed a form with two fields and one submission whose Name carries a
     * spreadsheet-formula payload (to prove neutralization survives selection).
     *
     * @return array{0: \anvildev\simpleform\elements\Submission}
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

        return [$result['submission']];
    }
}

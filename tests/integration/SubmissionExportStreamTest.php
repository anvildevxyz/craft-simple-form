<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SubmissionCsv;
use Craft;

/**
 * #340 — memory-bounded (streamed) CSV export. The streamed export paths hydrate
 * the result set in bounded batches instead of `$query->all()`, and MUST produce
 * output byte-for-byte identical to the former materialized-array path over the
 * same query. This seeds a varied set (two forms; plain, composite, and asset
 * fields; a column that only a later row/batch introduces; a shared asset id
 * across batches; quiz + attribution rows) and asserts that identity across
 * every streamed entry point, including at a batch size that crosses batches.
 *
 * @group requires-craft
 */
class SubmissionExportStreamTest extends SimpleFormTestCase
{
    /**
     * @return array<int, int> the seeded submission ids
     */
    private function seed(): array
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $formA = $this->createForm('Stream A', 'stream_a');
        $formB = $this->createForm('Stream B', 'stream_b');

        $ids = [];

        // Form A: plain text/email rows, one carrying a quiz score.
        $ids[] = $this->save($formA->id, $siteId, [
            'field_1' => ['label' => 'Full Name', 'type' => 'text', 'value' => 'Ada Lovelace'],
            'field_2' => ['label' => 'Email', 'type' => 'email', 'value' => 'ada@example.test'],
        ]);
        $ids[] = $this->save($formA->id, $siteId, [
            'field_1' => ['label' => 'Full Name', 'type' => 'text', 'value' => '=Grace'],
            'field_2' => ['label' => 'Email', 'type' => 'email', 'value' => 'grace@example.test'],
        ], quizScore: 7, quizMaxScore: 10);

        // Form B: a composite Name field + a signature asset. The first row stores
        // only first/last; the second adds "middle" — a column only a later row
        // introduces, so the column union must grow across rows/batches. The same
        // asset id 555111 recurs across rows to exercise the merged asset cache.
        $ids[] = $this->save($formB->id, $siteId, [
            'field_3' => ['label' => 'Your Name', 'type' => 'name', 'value' => ['first' => 'Ada', 'last' => 'Lovelace']],
            'field_4' => ['label' => 'Signature', 'type' => 'signature', 'value' => [555111]],
        ], attribution: ['utm_source' => 'newsletter', 'utm_medium' => 'email']);
        $ids[] = $this->save($formB->id, $siteId, [
            'field_3' => ['label' => 'Your Name', 'type' => 'name', 'value' => ['first' => 'Alan', 'middle' => 'M', 'last' => 'Turing']],
            'field_4' => ['label' => 'Signature', 'type' => 'signature', 'value' => [555111]],
        ]);

        return $ids;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, string>|null $attribution
     * @return int the saved submission id
     */
    private function save(
        int $formId,
        int $siteId,
        array $data,
        ?int $quizScore = null,
        ?int $quizMaxScore = null,
        ?array $attribution = null,
    ): int {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = $siteId;
        $sub->data = $data;
        $sub->readStatus = 'new';
        $sub->quizScore = $quizScore;
        $sub->quizMaxScore = $quizMaxScore;
        $sub->attribution = $attribution;
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub), 'Submission should save');

        return (int) $sub->id;
    }

    /**
     * A fresh, deterministically ordered query over just the seeded submissions,
     * so `->all()` and `->batch()` traverse the same rows in the same order.
     *
     * @param array<int, int> $ids
     */
    private function query(array $ids): \anvildev\simpleform\elements\db\SubmissionQuery
    {
        return Submission::find()->siteId('*')->id($ids)->orderBy(['id' => SORT_ASC]);
    }

    public function testStreamedCsvIsByteIdenticalToMaterialized(): void
    {
        $this->requireCraft();
        $ids = $this->seed();

        $legacy = SubmissionCsv::fromSubmissions($this->query($ids)->all());

        // Sanity: the varied fixture actually exercised the interesting paths —
        // a composite flattened column, a column only the last row/batch
        // introduces ("… — middle"), a resolved asset reference, and the gated
        // quiz + attribution columns.
        $this->assertStringContainsString('Your Name — First name', $legacy);
        $this->assertStringContainsString('Your Name — middle', $legacy);
        $this->assertStringContainsString('Asset #555111', $legacy);
        $this->assertStringContainsString('Score', $legacy);
        $this->assertStringContainsString('UTM Source', $legacy);

        // Default batch size, and a size that crosses batch boundaries.
        $this->assertSame($legacy, SubmissionCsv::streamQueryToString($this->query($ids)));
        $this->assertSame($legacy, SubmissionCsv::streamQueryToString($this->query($ids), null, 2));
        $this->assertSame($legacy, SubmissionCsv::streamQueryToString($this->query($ids), null, 1));
    }

    public function testStreamedCsvRespectsColumnSelectionIdentically(): void
    {
        $this->requireCraft();
        $ids = $this->seed();

        $only = ['meta:id', 'meta:submitted', 'field:field_3::first', 'quiz:score'];
        $legacy = SubmissionCsv::fromSubmissions($this->query($ids)->all(), $only);

        $this->assertSame($legacy, SubmissionCsv::streamQueryToString($this->query($ids), $only, 2));
    }

    public function testToRowsFromQueryIsByteIdenticalToMaterialized(): void
    {
        $this->requireCraft();
        $ids = $this->seed();

        $this->assertSame(
            SubmissionCsv::toRows($this->query($ids)->all()),
            SubmissionCsv::toRowsFromQuery($this->query($ids), null, 2),
        );
    }

    public function testAvailableColumnsForQueryMatchesMaterialized(): void
    {
        $this->requireCraft();
        $ids = $this->seed();

        $this->assertSame(
            SubmissionCsv::availableColumns($this->query($ids)->all()),
            SubmissionCsv::availableColumnsForQuery($this->query($ids), 2),
        );
    }

    /**
     * The GDPR export-by-email count must reflect rows actually written, not the
     * raw id scan: the id scan (findSubmissionIdsByEmail) can include trashed rows
     * that the exporting element query excludes. Reporting the raw count would
     * overstate a subject-access response.
     */
    public function testExportCountExcludesTrashedRows(): void
    {
        $this->requireCraft();
        $ids = $this->seed();

        // A raw id scan would still return all four; trash one so the element
        // query and the CSV drop it.
        Craft::$app->getElements()->deleteElementById($ids[0], Submission::class);

        $query = Submission::find()->siteId('*')->id($ids);
        $exported = (int) $query->count();

        $this->assertSame(count($ids) - 1, $exported, 'trashed match is excluded from the exported count');
        $this->assertNotSame(count($ids), $exported, 'raw id count would overstate the export');

        // Stream from the SAME query (the controller pattern) and confirm the file
        // holds exactly `$exported` data rows (header + N lines; fixture cells have
        // no embedded newlines).
        $csv = SubmissionCsv::streamQueryToString($query);
        $dataRows = substr_count(rtrim($csv, "\n"), "\n");
        $this->assertSame($exported, $dataRows, 'rows written must equal the reported count');
    }
}

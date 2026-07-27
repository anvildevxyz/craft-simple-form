<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\actions\SetSubmissionStatus;
use anvildev\simpleform\elements\exporters\SubmissionExporter;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use Craft;

/**
 * Submissions index bulk action + native exporter (#109).
 */
class SubmissionActionsTest extends SimpleFormTestCase
{
    private function makeSubmission(int $formId): int
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = ['field_1' => ['label' => 'Name', 'value' => 'Ada']];
        $sub->readStatus = SubmissionStatus::NEW;
        Craft::$app->getElements()->saveElement($sub);
        return (int) $sub->id;
    }

    public function testBulkSetStatusAction(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'actions_status');
        $id = $this->makeSubmission((int) $form->id);

        $action = new SetSubmissionStatus(['status' => SubmissionStatus::ARCHIVED]);
        $this->assertTrue($action->performAction(Submission::find()->id($id)));

        $reloaded = Submission::find()->id($id)->one();
        $this->assertInstanceOf(Submission::class, $reloaded);
        $this->assertSame(SubmissionStatus::ARCHIVED, $reloaded->readStatus);
    }

    public function testExporterProducesMetadataAndFieldColumns(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'actions_export');
        $this->makeSubmission((int) $form->id);

        $exporter = new SubmissionExporter();
        $rows = $exporter->export(Submission::find()->formId((int) $form->id));

        $this->assertNotEmpty($rows);
        $row = $rows[0];
        foreach (['ID', 'Form', 'Status', 'Submitted', 'Name'] as $col) {
            $this->assertArrayHasKey($col, $row, "exporter row should have the '$col' column");
        }
        $this->assertSame('Ada', $row['Name']);
    }

    public function testActionsAreRegisteredOnTheElement(): void
    {
        $this->requireCraft();
        // Smoke that defineActions/defineExporters resolve without error and
        // include our additions.
        $actions = Submission::actions('*');
        $this->assertNotEmpty($actions);

        $exporters = Submission::exporters('*');
        $found = false;
        foreach ($exporters as $exporter) {
            $class = is_string($exporter) ? $exporter : ($exporter['type'] ?? null);
            if ($class === SubmissionExporter::class) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'SubmissionExporter should be registered');
    }
}

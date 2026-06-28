<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\controllers\SubmissionsController;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SubmissionCsv;
use anvildev\simpleform\Plugin;
use Craft;

/**
 * #91 — CP CSV export: the CSV renderer and the export controller action.
 *
 * @group requires-craft
 */
class SubmissionExportTest extends SimpleFormTestCase
{
    private function seedForm(string $handle): array
    {
        $form = $this->createForm('Export', $handle);
        $nameId = $this->createField($form->id, 'text', 'name', 'Full Name');
        $emailId = $this->createField($form->id, 'email', 'email', 'Email Address');

        $service = Plugin::getInstance()->getSubmissionService();
        $service->submit($form, ['field_' . $nameId => 'Ada', 'field_' . $emailId => 'ada@example.test'], ['skipCaptcha' => true]);
        $service->submit($form, ['field_' . $nameId => 'Grace', 'field_' . $emailId => 'grace@example.test'], ['skipCaptcha' => true]);

        return [$form, $nameId, $emailId];
    }

    public function testCsvRendersHeaderAndRows(): void
    {
        $this->requireCraft();
        [$form] = $this->seedForm('export_csv');

        $submissions = Submission::find()->formId($form->id)->orderBy(['dateCreated' => SORT_ASC])->all();
        $csv = SubmissionCsv::fromSubmissions($submissions);

        // Header uses field labels alongside the metadata columns.
        $this->assertStringContainsString('Full Name', $csv);
        $this->assertStringContainsString('Email Address', $csv);
        $this->assertStringContainsString('ID,Form,Status,Submitted', $csv);

        // Both submissions' values are present.
        $this->assertStringContainsString('Ada', $csv);
        $this->assertStringContainsString('grace@example.test', $csv);

        // One header line + two data lines.
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(3, $lines);
    }

    public function testSignatureExportsAsAssetReferenceNotBase64(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Sig Export', 'export_signature');

        // A stored signature entry referencing an asset id (no public URL in the
        // test volume → an `Asset #id` reference, never the raw base64).
        $sub = new Submission();
        $sub->formId = (int) $form->id;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = [
            'field_1' => ['label' => 'Signature', 'type' => 'signature', 'value' => [555111]],
        ];
        $sub->readStatus = 'new';
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));

        $csv = SubmissionCsv::fromSubmissions(Submission::find()->formId($form->id)->all());

        $this->assertStringContainsString('Asset #555111', $csv);
        $this->assertStringNotContainsString('base64', $csv);
        $this->assertStringNotContainsString('data:image/png', $csv);
    }

    public function testExportActionReturnsCsvDownload(): void
    {
        $this->requireCraft();
        [$form] = $this->seedForm('export_action');

        Craft::$app->getRequest()->setQueryParams(['formId' => $form->id, 'status' => 'all']);

        $controller = new SubmissionsController('submissions', Plugin::getInstance());
        $response = $controller->actionExport();

        $this->assertStringContainsString('text/csv', (string) $response->getHeaders()->get('content-type'));
        $this->assertStringContainsString('Ada', (string) $response->content);
        $this->assertStringContainsString('Grace', (string) $response->content);
    }
}

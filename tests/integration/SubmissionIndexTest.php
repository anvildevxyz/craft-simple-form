<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;

/**
 * The submissions listing is Craft's native element index (#cp): the Submission
 * element defines per-status + per-form sources and renders its own columns. The
 * unit gate doesn't boot Craft, so the source structure and column HTML only
 * surface here.
 *
 * @group requires-craft
 */
class SubmissionIndexTest extends SimpleFormTestCase
{
    public function testSourcesIncludeStatusAndPerFormGroups(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Index Sources', 'idx_sources_' . uniqid());

        $sources = Submission::sources('index');
        $keys = array_column(array_filter($sources, static fn($s): bool => isset($s['key'])), 'key');

        // The catch-all and one source per read status.
        $this->assertContains('*', $keys);
        $this->assertContains('status:' . SubmissionStatus::NEW, $keys);
        $this->assertContains('status:' . SubmissionStatus::SPAM, $keys);
        $this->assertContains('status:' . SubmissionStatus::ARCHIVED, $keys);

        // A source for the seeded form (so the sidebar filters by form).
        $this->assertContains('form:' . $form->id, $keys);

        // The recoverable-delete Trashed source is still present.
        $this->assertContains('trashed', $keys);

        // The status source carries the readStatus criteria the index applies.
        foreach ($sources as $source) {
            if (($source['key'] ?? null) === 'status:' . SubmissionStatus::SPAM) {
                $this->assertSame(SubmissionStatus::SPAM, $source['criteria']['readStatus'] ?? null);
            }
        }
    }

    public function testColumnHtmlRendersStatusFormSpamAndUser(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Index Columns', 'idx_cols_' . uniqid());

        $submission = new Submission([
            'formId' => (int) $form->id,
            'readStatus' => SubmissionStatus::SPAM,
            'spamReason' => 'akismet',
            'userId' => null,
        ]);

        // Status renders a CP status dot + a human label.
        $statusHtml = $submission->getAttributeHtml('readStatus');
        $this->assertStringContainsString('class="status spam"', $statusHtml);
        $this->assertStringContainsString('Spam', $statusHtml);

        // The form column links to the form's filtered submissions, by title.
        $formHtml = $submission->getAttributeHtml('form');
        $this->assertStringContainsString('Index Columns', $formHtml);
        $this->assertStringContainsString('formId=' . $form->id, $formHtml);

        // The spam-reason column surfaces the stored reason.
        $this->assertStringContainsString('akismet', $submission->getAttributeHtml('spamReason'));

        // An anonymous submission shows a neutral dash for the user column.
        $this->assertStringContainsString('—', $submission->getAttributeHtml('userId'));
    }

    public function testCpEditUrlPointsAtTheDetailView(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Index Url', 'idx_url_' . uniqid());
        $service = \anvildev\simpleform\Plugin::getInstance()->getSubmissionService();
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $result = $service->submit($form, ['field_' . $fieldId => 'Jo'], ['skipCaptcha' => true]);

        $submission = $result['submission'];
        $this->assertNotNull($submission->id);
        $this->assertStringContainsString('simple-form/submissions/' . $submission->id, (string) $submission->getCpEditUrl());
    }
}

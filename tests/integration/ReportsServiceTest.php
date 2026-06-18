<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\Plugin;

/**
 * Submissions analytics aggregates (#111).
 */
class ReportsServiceTest extends SimpleFormTestCase
{
    private int $siteId;

    private function makeSubmission(int $formId, string $status = SubmissionStatus::NEW): void
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = $this->siteId;
        $sub->data = ['field_1' => ['label' => 'Name', 'value' => 'Ada']];
        $sub->readStatus = $status;
        Craft::$app->getElements()->saveElement($sub);
    }

    public function testAggregates(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $form = $this->createForm('Contact', 'reports_form');
        $formId = (int) $form->id;

        $this->makeSubmission($formId, SubmissionStatus::NEW);
        $this->makeSubmission($formId, SubmissionStatus::NEW);
        $this->makeSubmission($formId, SubmissionStatus::SPAM);

        $reports = Plugin::getInstance()->getReports();

        $stats = $reports->statusBreakdown($this->siteId, $formId);
        $this->assertSame(3, $stats['total']);
        $this->assertSame(2, $stats['new']);
        $this->assertSame(1, $stats['spam']);

        $spam = $reports->spamRate($this->siteId, $formId);
        $this->assertSame(1, $spam['spam']);
        $this->assertSame(2, $spam['ham']);

        $perDay = $reports->submissionsPerDay($this->siteId, 30, $formId);
        $this->assertCount(30, $perDay);
        $today = end($perDay);
        $this->assertSame((new \DateTime('today', new \DateTimeZone('UTC')))->format('Y-m-d'), $today['date']);
        $this->assertSame(3, $today['count'], 'today bucket should hold all three submissions');

        $perForm = $reports->perFormTotals($this->siteId);
        $match = array_values(array_filter($perForm, static fn(array $r): bool => $r['formId'] === $formId));
        $this->assertNotEmpty($match);
        $this->assertSame(3, $match[0]['count']);

        $dispatch = $reports->dispatchHealth();
        $this->assertArrayHasKey('success', $dispatch);
        $this->assertArrayHasKey('failed', $dispatch);
        $this->assertArrayHasKey('pending', $dispatch);
    }
}

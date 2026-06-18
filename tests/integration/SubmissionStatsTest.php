<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\Plugin;

/**
 * #99 — the submissions-index stat cards count through the same element query
 * the listing uses (siteId + readStatus), so the totals can never diverge from
 * the rows shown in the table. Regression guard for the old raw-COUNT on the
 * simpleform_submissions.siteId column, which reported zero while rows listed
 * because the element's site (elements_sites) differed from that column.
 *
 * @group requires-craft
 */
class SubmissionStatsTest extends SimpleFormTestCase
{
    private function setStatus(Submission $submission, string $status): void
    {
        $submission->readStatus = $status;
        $this->assertTrue(Craft::$app->getElements()->saveElement($submission, false));
    }

    /**
     * @return array<string, int> total/new/read/archived/spam, mirroring the
     *                            controller's element-query stat computation
     */
    private function stats(int $siteId, int $formId): array
    {
        $count = function (?string $status) use ($siteId, $formId): int {
            $query = Submission::find()->siteId($siteId)->formId($formId);
            if ($status !== null) {
                $query->status($status);
            }

            return (int) $query->count();
        };

        return [
            'total' => $count(null),
            'new' => $count(SubmissionStatus::NEW),
            'read' => $count(SubmissionStatus::READ),
            'archived' => $count(SubmissionStatus::ARCHIVED),
            'spam' => $count(SubmissionStatus::SPAM),
        ];
    }

    public function testStatsMatchListingAcrossStatuses(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Stats', 'stats_form');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name');
        $service = Plugin::getInstance()->getSubmissionService();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        // Four fresh (new) submissions.
        for ($i = 0; $i < 4; $i++) {
            $service->submit($form, ['field_' . $fieldId => 'P' . $i], ['skipCaptcha' => true]);
        }

        $all = Submission::find()->siteId($siteId)->formId($form->id)->all();
        $this->assertCount(4, $all, 'sanity: four submissions are listable on the current site');

        // Flip two to read and one to spam.
        $this->setStatus($all[0], SubmissionStatus::READ);
        $this->setStatus($all[1], SubmissionStatus::READ);
        $this->setStatus($all[2], SubmissionStatus::SPAM);

        $stats = $this->stats($siteId, (int) $form->id);

        $this->assertSame(4, $stats['total'], 'total counts every status');
        $this->assertSame(1, $stats['new']);
        $this->assertSame(2, $stats['read']);
        $this->assertSame(0, $stats['archived']);
        $this->assertSame(1, $stats['spam']);

        // The per-status counts must sum to the listable total — the invariant
        // the old raw-column count violated.
        $this->assertSame(
            $stats['total'],
            $stats['new'] + $stats['read'] + $stats['archived'] + $stats['spam'],
        );
    }
}

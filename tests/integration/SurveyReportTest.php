<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\models\Site;
use craft\models\SiteGroup;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\Plugin;

/**
 * End-to-end coverage for the per-form survey report (#240): the field-type
 * aggregatability declarations drive a real ReportsService::fieldReport() over
 * stored submissions, with spam excluded, a date-range window, and per-site
 * scoping.
 *
 * @group requires-craft
 */
class SurveyReportTest extends SimpleFormTestCase
{
    private int $siteId;

    /**
     * @param array<string, mixed> $data
     */
    private function makeSubmission(int $formId, array $data, int $siteId, string $status = SubmissionStatus::NEW): Submission
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = $siteId;
        $sub->data = $data;
        $sub->readStatus = $status;
        Craft::$app->getElements()->saveElement($sub);
        return $sub;
    }

    private function backdate(int $submissionId, string $date): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%simpleform_submissions}}',
            ['dateCreated' => $date],
            ['id' => $submissionId],
        )->execute();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function reportByKey(int $formId, ?string $from = null, ?string $to = null): array
    {
        $out = [];
        foreach (Plugin::getInstance()->getReports()->fieldReport($this->siteId, $formId, $from, $to) as $row) {
            $out[$row['key']] = $row;
        }
        return $out;
    }

    public function testFieldReportAggregatesEveryKindAndExcludesSpam(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->createForm('Survey', 'surveyReportForm', 'Survey');
        $formId = (int) $form->id;

        $colourId = $this->createField($formId, 'select', 'colour', 'Colour', false, [
            'options' => [
                ['value' => 'red', 'label' => 'Red'],
                ['value' => 'green', 'label' => 'Green'],
                ['value' => 'blue', 'label' => 'Blue'],
            ],
        ]);
        $scoreId = $this->createField($formId, 'rating', 'score', 'Score', false, ['max' => 5]);
        $commentsId = $this->createField($formId, 'textarea', 'comments', 'Comments');

        $colourKey = 'field_' . $colourId;
        $scoreKey = 'field_' . $scoreId;
        $commentsKey = 'field_' . $commentsId;

        $this->makeSubmission($formId, [
            $colourKey => ['label' => 'Colour', 'type' => 'select', 'value' => 'red'],
            $scoreKey => ['label' => 'Score', 'type' => 'rating', 'value' => 5],
            $commentsKey => ['label' => 'Comments', 'type' => 'textarea', 'value' => 'Great'],
        ], $this->siteId);
        $this->makeSubmission($formId, [
            $colourKey => ['label' => 'Colour', 'type' => 'select', 'value' => 'red'],
            $scoreKey => ['label' => 'Score', 'type' => 'rating', 'value' => 3],
        ], $this->siteId);
        // Spam must not influence the report.
        $this->makeSubmission($formId, [
            $colourKey => ['label' => 'Colour', 'type' => 'select', 'value' => 'blue'],
            $scoreKey => ['label' => 'Score', 'type' => 'rating', 'value' => 1],
        ], $this->siteId, SubmissionStatus::SPAM);

        $report = $this->reportByKey($formId);

        $this->assertSame(2, Plugin::getInstance()->getReports()->responseCount($this->siteId, $formId));

        // Choice — per-option counts, spam's blue excluded, zero-filled green.
        $colour = $report[$colourKey];
        $this->assertSame('choice', $colour['kind']);
        $this->assertSame(2, $colour['count']);
        $counts = array_column($colour['options'], 'count', 'value');
        $this->assertSame(['red' => 2, 'green' => 0, 'blue' => 0], $counts);

        // Scale — average + distribution over the two non-spam scores.
        $score = $report[$scoreKey];
        $this->assertSame('scale', $score['kind']);
        $this->assertSame(2, $score['count']);
        $this->assertSame(4.0, $score['average']);
        $this->assertSame([1 => 0, 2 => 0, 3 => 1, 4 => 0, 5 => 1], $score['distribution']);

        // Free-form — response count only, no chart payload.
        $comments = $report[$commentsKey];
        $this->assertSame('none', $comments['kind']);
        $this->assertSame(1, $comments['count']);
        $this->assertArrayNotHasKey('distribution', $comments);
    }

    public function testDateRangeFilterScopesTheWindow(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->createForm('Dated', 'datedReportForm', 'Dated');
        $formId = (int) $form->id;
        $scoreId = $this->createField($formId, 'rating', 'score', 'Score', false, ['max' => 5]);
        $key = 'field_' . $scoreId;

        $old = $this->makeSubmission($formId, [$key => ['type' => 'rating', 'value' => 2]], $this->siteId);
        $this->backdate((int) $old->id, '2020-01-01 09:00:00');
        $this->makeSubmission($formId, [$key => ['type' => 'rating', 'value' => 4]], $this->siteId);

        // Without a filter, both count.
        $this->assertSame(2, Plugin::getInstance()->getReports()->responseCount($this->siteId, $formId));

        // A window that excludes the backdated one leaves a single response.
        $report = $this->reportByKey($formId, '2021-01-01');
        $this->assertSame(1, $report[$key]['count']);
        $this->assertSame(4.0, $report[$key]['average']);
        $this->assertSame(
            1,
            Plugin::getInstance()->getReports()->responseCount($this->siteId, $formId, '2021-01-01'),
        );

        // A window that only contains the backdated one.
        $reportOld = $this->reportByKey($formId, '2019-12-01', '2020-12-31');
        $this->assertSame(1, $reportOld[$key]['count']);
        $this->assertSame(2.0, $reportOld[$key]['average']);
    }

    public function testReportIsScopedPerSite(): void
    {
        $this->requireCraft();
        $primaryId = Craft::$app->getSites()->getCurrentSite()->id;
        $secondSite = $this->createSecondSite();

        $this->siteId = $primaryId;
        $form = $this->createForm('Scoped', 'scopedReportForm', 'Scoped');
        $formId = (int) $form->id;
        $scoreId = $this->createField($formId, 'rating', 'score', 'Score', false, ['max' => 5]);
        $key = 'field_' . $scoreId;

        // One submission per site. Submissions are localized, and their storage
        // table keeps a single row per id with one `siteId` column — which the
        // report scopes on. Pin each row's site explicitly so the scoping test
        // doesn't hinge on multi-site element-propagation order.
        $first = $this->makeSubmission($formId, [$key => ['type' => 'rating', 'value' => 5]], $primaryId);
        $second = $this->makeSubmission($formId, [$key => ['type' => 'rating', 'value' => 1]], $primaryId);
        $db = Craft::$app->getDb();
        $db->createCommand()->update('{{%simpleform_submissions}}', ['siteId' => $primaryId], ['id' => $first->id])->execute();
        $db->createCommand()->update('{{%simpleform_submissions}}', ['siteId' => $secondSite->id], ['id' => $second->id])->execute();

        $reports = Plugin::getInstance()->getReports();

        $this->assertSame(1, $reports->responseCount($primaryId, $formId), 'primary site sees only its own row');
        $this->assertSame(1, $reports->responseCount($secondSite->id, $formId), 'second site sees only its own row');

        $primary = [];
        foreach ($reports->fieldReport($primaryId, $formId) as $row) {
            $primary[$row['key']] = $row;
        }
        $this->assertSame(5.0, $primary[$key]['average'], 'primary average reflects only the primary-site value');
    }

    private function createSecondSite(): Site
    {
        $sitesService = Craft::$app->getSites();

        foreach ($sitesService->getAllSites() as $existing) {
            if ($existing->handle === 'integrationSecondSite') {
                return $existing;
            }
        }

        $primary = $sitesService->getPrimarySite();

        $site = new Site([
            'groupId' => $primary->groupId ?? $this->firstGroupId(),
            'name' => 'Integration Second Site',
            'handle' => 'integrationSecondSite',
            'language' => 'de',
            'hasUrls' => false,
            'primary' => false,
        ]);

        $this->assertTrue(
            $sitesService->saveSite($site),
            'Second site should save: ' . implode(', ', $site->getFirstErrors()),
        );

        return $site;
    }

    private function firstGroupId(): int
    {
        $groups = Craft::$app->getSites()->getAllGroups();
        if (!empty($groups)) {
            return (int) $groups[0]->id;
        }

        $group = new SiteGroup(['name' => 'Test Group']);
        Craft::$app->getSites()->saveGroup($group);
        return (int) $group->id;
    }
}

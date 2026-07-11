<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\ReportsService;
use Craft;

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

    /**
     * The test app runs in devMode (via CRAFT_DEV_MODE) with a dummy cache, both
     * of which bypass the reports cache. Swap in a real in-memory cache and a
     * reports service with caching forced on so the caching contract is actually
     * exercised, then restore. Returns the forced-caching reports service.
     *
     * @param callable(ReportsService): void $fn
     */
    private function withActiveCache(callable $fn): void
    {
        $prevCache = Craft::$app->getCache();
        Craft::$app->set('cache', new \yii\caching\ArrayCache());

        $reports = new class() extends ReportsService {
            protected function cachingEnabled(): bool
            {
                return true;
            }
        };

        try {
            $fn($reports);
        } finally {
            Craft::$app->set('cache', $prevCache);
        }
    }

    public function testStatsCacheServesUntilInvalidated(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->createForm('Cache', 'reports_cache');
        $formId = (int) $form->id;

        $this->makeSubmission($formId, SubmissionStatus::NEW);
        $this->makeSubmission($formId, SubmissionStatus::NEW);

        $this->withActiveCache(function(ReportsService $reports) use ($formId): void {
            // Warm the cache: total new = 2.
            $this->assertSame(2, $reports->statusBreakdown($this->siteId, $formId)['new']);

            // Change the data underneath WITHOUT the element save that would
            // invalidate the cache.
            Craft::$app->getDb()->createCommand()->update(
                '{{%simpleform_submissions}}',
                ['readStatus' => SubmissionStatus::ARCHIVED],
                ['formId' => $formId, 'readStatus' => SubmissionStatus::NEW],
            )->execute();

            // Still served from cache — the stale total survives until invalidated.
            $this->assertSame(2, $reports->statusBreakdown($this->siteId, $formId)['new'], 'cached total should survive an out-of-band change');

            $reports->invalidateCache();

            $fresh = $reports->statusBreakdown($this->siteId, $formId);
            $this->assertSame(0, $fresh['new'], 'invalidation must recompute from the DB');
            $this->assertSame(2, $fresh['archived']);
        });
    }

    public function testFormRenameInvalidatesCache(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->createForm('Original Name', 'reports_form_rename');
        $formId = (int) $form->id;

        $nameFor = function(array $totals) use ($formId): ?string {
            foreach ($totals as $row) {
                if ($row['formId'] === $formId) {
                    return $row['name'];
                }
            }
            return null;
        };

        $this->withActiveCache(function(ReportsService $reports) use ($form, $nameFor): void {
            // Warm the per-form list, then rename via an element save (which fires
            // Form::afterSave, not Submission::afterSave).
            $this->assertSame('Original Name', $nameFor($reports->perFormTotals($this->siteId)));

            $form->title = 'Renamed';
            $this->assertTrue(Craft::$app->getElements()->saveElement($form));

            $this->assertSame(
                'Renamed',
                $nameFor($reports->perFormTotals($this->siteId)),
                'renaming a form must invalidate the cached per-form list',
            );
        });
    }

    public function testElementSaveInvalidatesCache(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->createForm('Cache Save', 'reports_cache_save');
        $formId = (int) $form->id;

        $this->withActiveCache(function(ReportsService $reports) use ($formId): void {
            $this->makeSubmission($formId, SubmissionStatus::NEW);
            $this->assertSame(1, $reports->statusBreakdown($this->siteId, $formId)['total']);

            // A genuine element save fires Submission::afterSave, which invalidates
            // the shared reports tag, so the next read must reflect it.
            $this->makeSubmission($formId, SubmissionStatus::NEW);
            $this->assertSame(2, $reports->statusBreakdown($this->siteId, $formId)['total'], 'saving a submission must invalidate the cached total');
        });
    }
}

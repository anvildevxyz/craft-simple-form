<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * The plugin's landing screen: an at-a-glance dashboard of submission activity,
 * health, and recent entries. Reuses {@see \anvildev\simpleform\services\ReportsService}
 * so its numbers agree with the analytics page.
 *
 * No hard PERMISSION const: the dashboard is the section root, so a user who
 * can't view submissions is forwarded to the first screen they *can* reach
 * rather than hitting a 403.
 *
 * @phpstan-import-type DailyCount from \anvildev\simpleform\services\ReportsService
 */
class DashboardController extends Controller
{
    use SimpleFormControllerTrait;

    /** How many recent submissions to surface on the dashboard. */
    private const RECENT_LIMIT = 8;

    /** Trailing window (days) for the "submissions over time" chart. */
    private const CHART_DAYS = 30;

    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();
        $isAdmin = (bool) $user?->admin;
        $canViewSubmissions = $isAdmin || (bool) $user?->can(SimpleFormPermissions::VIEW_SUBMISSIONS);

        // The dashboard is submission-centric. Forward users without submission
        // access to whichever section they can actually use. An admin always
        // passes the check above, so $isAdmin is necessarily false here.
        if (!$canViewSubmissions) {
            if ($user?->can(SimpleFormPermissions::MANAGE_FORMS)) {
                return $this->redirect('simple-form/forms');
            }
            if ($user?->can(SimpleFormPermissions::MANAGE_SETTINGS)) {
                return $this->redirect('simple-form/settings');
            }
            throw new ForbiddenHttpException();
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $reports = Plugin::getInstance()->getReports();

        $breakdown = $reports->statusBreakdown($siteId);
        $perDay = $reports->submissionsPerDay($siteId, self::CHART_DAYS);

        // Today's and the trailing-7-day totals fall straight out of the daily
        // series — no extra queries.
        $today = $perDay !== [] ? (int) $perDay[array_key_last($perDay)]['count'] : 0;
        $last7 = 0;
        foreach (array_slice($perDay, -7) as $point) {
            $last7 += (int) $point['count'];
        }

        return $this->renderTemplate('simple-form/dashboard/index', [
            'byWeekday' => $this->byWeekday($perDay),
            'title' => Craft::t('simple-form', 'Dashboard'),
            'formCount' => (int) Form::find()->siteId($siteId)->status(null)->count(),
            'enabledFormCount' => (int) Form::find()->siteId($siteId)->status('enabled')->count(),
            'stats' => $breakdown,
            'today' => $today,
            'last7' => $last7,
            'perDay' => $perDay,
            'chartDays' => self::CHART_DAYS,
            'perForm' => array_slice($reports->perFormTotals($siteId), 0, 5),
            'recent' => $this->recentSubmissions($siteId),
            'failedDispatches' => Plugin::getInstance()->getIntegrations()->countFailedDispatches(),
            'canManageForms' => $isAdmin || (bool) $user?->can(SimpleFormPermissions::MANAGE_FORMS),
            'hasAnySubmissions' => $breakdown['total'] > 0,
        ]);
    }

    /**
     * Bucket the daily series into submissions-per-weekday (Mon→Sun), so the
     * dashboard can show *when* submissions tend to arrive. Derived from the
     * already-loaded `perDay` data — no extra query, and DB-agnostic (no
     * weekday SQL function that would differ across MySQL/Postgres).
     *
     * @param list<DailyCount> $perDay
     * @return list<int> seven counts, index 0 = Monday … 6 = Sunday
     */
    private function byWeekday(array $perDay): array
    {
        $buckets = array_fill(0, 7, 0);
        foreach ($perDay as $point) {
            // ISO-8601 day of week: 1 (Mon) … 7 (Sun) → 0 … 6.
            $dow = (int) (new \DateTime($point['date']))->format('N') - 1;
            $buckets[$dow] += (int) $point['count'];
        }

        return $buckets;
    }

    /**
     * The most recent non-spam submissions for the site, parent forms eager-loaded.
     *
     * @return list<Submission>
     */
    private function recentSubmissions(int $siteId): array
    {
        return Submission::find()
            ->siteId($siteId)
            ->andWhere(['not', ['simpleform_submissions.readStatus' => SubmissionStatus::SPAM]])
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(self::RECENT_LIMIT)
            ->with(['form'])
            ->all();
    }
}

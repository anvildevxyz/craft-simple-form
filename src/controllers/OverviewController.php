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
 * The plugin's landing screen: an at-a-glance overview of submission activity,
 * health, and recent entries. Reuses {@see \anvildev\simpleform\services\ReportsService}
 * so its numbers agree with the analytics dashboard.
 *
 * No hard PERMISSION const: the overview is the section root, so a user who
 * can't view submissions is forwarded to the first screen they *can* reach
 * rather than hitting a 403.
 */
class OverviewController extends Controller
{
    use SimpleFormControllerTrait;

    /** How many recent submissions to surface on the overview. */
    private const RECENT_LIMIT = 8;

    /** Trailing window (days) for the "submissions over time" chart. */
    private const CHART_DAYS = 30;

    public function actionIndex(): Response
    {
        $user = Craft::$app->getUser()->getIdentity();
        $isAdmin = (bool) $user?->admin;
        $canViewSubmissions = $isAdmin || (bool) $user?->can(SimpleFormPermissions::VIEW_SUBMISSIONS);

        // The overview is submission-centric. Forward users without submission
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

        $canManageForms = $isAdmin || (bool) $user?->can(SimpleFormPermissions::MANAGE_FORMS);

        return $this->renderTemplate('simple-form/overview/index', [
            'title' => Craft::t('simple-form', 'Overview'),
            'formCount' => (int) Form::find()->siteId($siteId)->status(null)->count(),
            'stats' => $breakdown,
            'today' => $today,
            'last7' => $last7,
            'perDay' => $perDay,
            'chartDays' => self::CHART_DAYS,
            'perForm' => array_slice($reports->perFormTotals($siteId), 0, 5),
            'recent' => $this->recentSubmissions($siteId),
            'failedDispatches' => Plugin::getInstance()->getIntegrations()->countFailedDispatches(),
            'canManageForms' => $canManageForms,
            'hasAnySubmissions' => $breakdown['total'] > 0,
        ]);
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

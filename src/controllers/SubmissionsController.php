<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use fabianhaef\simpleform\Plugin;
use yii\web\Response;

class SubmissionsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::VIEW_SUBMISSIONS;

    public function beforeAction($action): bool
    {
        // Check base VIEW_SUBMISSIONS permission for all actions
        if (!parent::beforeAction($action)) {
            return false;
        }

        // Additionally require MANAGE_SUBMISSIONS for toggleStatus
        if ($action->id === 'toggle-status') {
            $this->requirePermission(SimpleFormPermissions::MANAGE_SUBMISSIONS);
        }

        return true;
    }
    /**
     * Build the submissions query from the current request's filters (form,
     * status, search, date range) for the current site — shared by the index
     * listing and the CSV export so both honor the same filters.
     */
    private function buildFilteredQuery(\craft\web\Request $request, int $siteId): \fabianhaef\simpleform\elements\db\SubmissionQuery
    {
        $query = Submission::find()
            ->siteId($siteId)
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($formId = $request->getQueryParam('formId')) {
            $query->formId((int)$formId);
        }
        $status = $request->getQueryParam('status', SubmissionStatus::NEW);
        if ($status && $status !== 'all') {
            $query->status($status);
        }
        if ($search = $request->getQueryParam('search')) {
            $query->search($search);
        }
        if ($dateFrom = $request->getQueryParam('dateFrom')) {
            $query->andWhere(['>=', 'elements.dateCreated', $dateFrom . ' 00:00:00']);
        }
        if ($dateTo = $request->getQueryParam('dateTo')) {
            $query->andWhere(['<=', 'elements.dateCreated', $dateTo . ' 23:59:59']);
        }

        return $query;
    }

    public function actionExport(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $submissions = $this->buildFilteredQuery($request, $siteId)->all();
        $csv = \fabianhaef\simpleform\helpers\SubmissionCsv::fromSubmissions($submissions);

        return $this->response->sendContentAsFile($csv, 'submissions.csv', [
            'mimeType' => 'text/csv',
        ]);
    }

    public function actionIndex(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $formId = $request->getQueryParam('formId');
        $status = $request->getQueryParam('status', SubmissionStatus::NEW);
        $search = $request->getQueryParam('search');
        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $query = $this->buildFilteredQuery($request, $siteId);

        // Store total count before pagination
        $total = $query->count();

        // Pagination
        $page = (int) ($request->getQueryParam('page') ?? 1);
        $perPage = $request->getQueryParam('perPage', 50);
        $query->offset(($page - 1) * $perPage)
            ->limit($perPage);

        $submissions = $query->all();

        // Get all forms for filter dropdown
        $allForms = Form::find()
            ->siteId($siteId)
            ->orderBy(['title' => SORT_ASC])
            ->all();

        // Get submission statistics
        $stats = $this->getSubmissionStats($siteId, $formId !== null ? (int) $formId : null);

        return $this->renderTemplate('simple-form/submissions/index', [
            'submissions' => $submissions,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'formId' => $formId,
            'status' => $status,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'forms' => $allForms,
            'stats' => $stats,
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function getSubmissionStats(int $siteId, ?int $formId = null): array
    {
        $db = Craft::$app->getDb();
        $baseQuery = 'SELECT COUNT(*) FROM {{%simpleform_submissions}} WHERE siteId = :siteId';
        $params = [':siteId' => $siteId];

        if ($formId) {
            $baseQuery .= ' AND formId = :formId';
            $params[':formId'] = $formId;
        }

        return [
            'total' => (int) $db->createCommand($baseQuery, $params)->queryScalar(),
            'new' => (int) $db->createCommand($baseQuery . ' AND readStatus = :status', array_merge($params, [':status' => SubmissionStatus::NEW]))->queryScalar(),
            'read' => (int) $db->createCommand($baseQuery . ' AND readStatus = :status', array_merge($params, [':status' => SubmissionStatus::READ]))->queryScalar(),
            'archived' => (int) $db->createCommand($baseQuery . ' AND readStatus = :status', array_merge($params, [':status' => SubmissionStatus::ARCHIVED]))->queryScalar(),
            'spam' => (int) $db->createCommand($baseQuery . ' AND readStatus = :status', array_merge($params, [':status' => SubmissionStatus::SPAM]))->queryScalar(),
        ];
    }

    public function actionView(int $submissionId): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $submission = Submission::find()
            ->siteId($siteId)
            ->id($submissionId)
            ->one();

        if (!$submission) {
            throw new \yii\web\NotFoundHttpException('Submission not found');
        }

        $form = $submission->getForm();
        // $submission->data is stored as a decoded array (json column).
        $data = is_array($submission->data) ? $submission->data : [];

        // Integration dispatch history for this submission, latest per integration.
        $integrationsService = Plugin::getInstance()->getIntegrations();
        $logs = $integrationsService->getLogsForSubmission((int) $submission->id);
        $integrations = $form ? $integrationsService->getIntegrationsForForm((int) $form->id) : [];
        $integrationNames = [];
        foreach ($integrations as $integration) {
            $integrationNames[(int) $integration->id] = $integration->name;
        }

        return $this->renderTemplate('simple-form/submissions/view', [
            'submission' => $submission,
            'form' => $form,
            'data' => $data,
            'integrationLogs' => $logs,
            'integrationNames' => $integrationNames,
            'canManageIntegrations' => Craft::$app->getUser()->checkPermission(SimpleFormPermissions::MANAGE_INTEGRATIONS),
        ]);
    }

    public function actionToggleStatus(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $submissionId = $request->getRequiredBodyParam('submissionId');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $submission = Submission::find()
            ->siteId($siteId)
            ->id($submissionId)
            ->one();

        if (!$submission) {
            return $this->asJson(['success' => false, 'error' => 'Submission not found']);
        }

        $statuses = SubmissionStatus::all();
        $currentIndex = array_search($submission->readStatus, $statuses);
        $nextIndex = ($currentIndex + 1) % count($statuses);
        $submission->readStatus = $statuses[$nextIndex];

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return $this->asJson(['success' => false, 'error' => 'Failed to save status']);
        }

        return $this->asJson([
            'success' => true,
            'status' => $submission->readStatus,
        ]);
    }
}

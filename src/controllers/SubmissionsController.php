<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;
use yii\base\Action;
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
    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $formId = $request->getQueryParam('formId');
        $status = $request->getQueryParam('status', 'new');
        $search = $request->getQueryParam('search');
        $dateFrom = $request->getQueryParam('dateFrom');
        $dateTo = $request->getQueryParam('dateTo');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $query = Submission::find()
            ->siteId($siteId)
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($formId) {
            $query->formId((int)$formId);
        }

        if ($status && $status !== 'all') {
            $query->status($status);
        }

        if ($search) {
            $query->search($search);
        }

        if ($dateFrom) {
            $query->andWhere(['>=', 'elements.dateCreated', $dateFrom . ' 00:00:00']);
        }

        if ($dateTo) {
            $query->andWhere(['<=', 'elements.dateCreated', $dateTo . ' 23:59:59']);
        }

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
        $stats = $this->getSubmissionStats($siteId, $formId);

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
            'new' => (int) $db->createCommand($baseQuery . ' AND readStatus = :status', array_merge($params, [':status' => 'new']))->queryScalar(),
            'read' => (int) $db->createCommand($baseQuery . ' AND readStatus = :status', array_merge($params, [':status' => 'read']))->queryScalar(),
            'archived' => (int) $db->createCommand($baseQuery . ' AND readStatus = :status', array_merge($params, [':status' => 'archived']))->queryScalar(),
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
        $data = json_decode($submission->data, true) ?? [];

        return $this->renderTemplate('simple-form/submissions/view', [
            'submission' => $submission,
            'form' => $form,
            'data' => $data,
        ]);
    }

    public function actionToggleStatus(): Response
    {
        $this->requirePostRequest();
        $this->requireAjax();

        $submissionId = Craft::$app->getRequest()->getRequiredBodyParam('submissionId');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $submission = Submission::find()
            ->siteId($siteId)
            ->id($submissionId)
            ->one();

        if (!$submission) {
            return $this->asJson(['success' => false, 'error' => 'Submission not found']);
        }

        $statuses = ['new', 'read', 'archived'];
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

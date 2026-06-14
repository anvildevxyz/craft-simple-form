<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Submission;
use yii\web\Response;

class SubmissionsController extends Controller
{
    public function actionIndex(): Response
    {
        $formId = Craft::$app->getRequest()->getQueryParam('formId');
        $status = Craft::$app->getRequest()->getQueryParam('status');
        $search = Craft::$app->getRequest()->getQueryParam('search');

        $query = Submission::find();

        if ($formId) {
            $query->formId($formId);
        }

        if ($status) {
            $query->readStatus($status);
        }

        if ($search) {
            $query->search($search);
        }

        // Pagination
        $page = (int) (Craft::$app->getRequest()->getQueryParam('page') ?? 1);
        $perPage = 50;
        $query->offset(($page - 1) * $perPage)
            ->limit($perPage);

        $submissions = $query->all();
        $total = $query->count();

        // Get all forms for filter dropdown
        $allForms = Craft::$app->getDb()->createCommand(
            'SELECT id, title, name FROM {{%simpleform_forms}} ORDER BY title ASC'
        )->queryAll();

        return $this->renderTemplate('simple-form/submissions/index', [
            'submissions' => $submissions,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'formId' => $formId,
            'status' => $status,
            'search' => $search,
            'forms' => $allForms,
        ]);
    }

    public function actionView(int $submissionId): Response
    {
        $submission = Submission::find()
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
        $submission = Submission::find()->id($submissionId)->one();

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

<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use yii\web\Response;

class SubmissionsController extends Controller
{
    public function actionIndex(): Response
    {
        $request = Craft::$app->getRequest();
        $formId = $request->getQueryParam('formId');
        $status = $request->getQueryParam('status');
        $search = $request->getQueryParam('search');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $query = Submission::find()
            ->siteId($siteId)
            ->orderBy(['dateCreated' => SORT_DESC]);

        if ($formId) {
            $query->formId((int)$formId);
        }

        if ($status) {
            $query->status($status);
        }

        if ($search) {
            $query->search($search);
        }

        // Store total count before pagination
        $total = $query->count();

        // Pagination
        $page = (int) ($request->getQueryParam('page') ?? 1);
        $perPage = 50;
        $query->offset(($page - 1) * $perPage)
            ->limit($perPage);

        $submissions = $query->all();

        // Get all forms for filter dropdown
        $allForms = Form::find()
            ->siteId($siteId)
            ->orderBy(['title' => SORT_ASC])
            ->all();

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

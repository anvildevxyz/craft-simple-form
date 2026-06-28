<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\Plugin;
use Craft;
use craft\web\Controller;
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

        // Mutating actions additionally require MANAGE_SUBMISSIONS.
        if (in_array($action->id, ['toggle-status', 'mark-not-spam', 'delete', 'transition'], true)) {
            $this->requirePermission(SimpleFormPermissions::MANAGE_SUBMISSIONS);
        }

        return true;
    }


    /**
     * Build the submissions query from the current request's filters (form,
     * status, search, date range) for the current site — shared by the index
     * listing and the CSV export so both honor the same filters.
     */
    private function buildFilteredQuery(\craft\web\Request $request, int $siteId): \anvildev\simpleform\elements\db\SubmissionQuery
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
        // Approval-workflow stage filter (#248).
        if ($workflow = $request->getQueryParam('workflow')) {
            $query->workflowStatus($workflow);
        }
        // F17 (CWE-20): only accept well-formed YYYY-MM-DD dates. The value is
        // already bound as a query parameter (no SQL injection), but validating
        // the shape avoids malformed date literals producing surprising results.
        $dateFrom = $request->getQueryParam('dateFrom');
        if (is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $query->andWhere(['>=', 'elements.dateCreated', "$dateFrom 00:00:00"]);
        }
        $dateTo = $request->getQueryParam('dateTo');
        if (is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $query->andWhere(['<=', 'elements.dateCreated', "$dateTo 23:59:59"]);
        }

        return $query;
    }

    public function actionExport(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $csv = \anvildev\simpleform\helpers\SubmissionCsv::fromSubmissions(
            $this->buildFilteredQuery($request, $siteId)->all()
        );

        return $this->response->sendContentAsFile($csv, 'submissions.csv', ['mimeType' => 'text/csv']);
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

        // Pagination. F6 (CWE-770): clamp perPage so a caller can't request the
        // entire table (e.g. ?perPage=99999999) and exhaust memory.
        $page = max(1, (int) ($request->getQueryParam('page') ?? 1));
        $perPage = max(1, min((int) $request->getQueryParam('perPage', 50), 500));
        $query->offset(($page - 1) * $perPage)->limit($perPage);

        return $this->renderTemplate('simple-form/submissions/index', [
            'submissions' => $query->all(),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'formId' => $formId,
            'status' => $status,
            'search' => $search,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'forms' => Form::find()->siteId($siteId)->orderBy(['title' => SORT_ASC])->all(),
            'stats' => Plugin::getInstance()->getReports()->statusBreakdown($siteId, $formId !== null ? (int) $formId : null),
            // Approval-workflow column + filter (#248); empty/false when off.
            'workflowEnabled' => Plugin::getInstance()->getWorkflow()->isEnabled(),
            'workflowStatuses' => Plugin::getInstance()->getWorkflow()->getStatuses(),
            'workflowFilter' => $request->getQueryParam('workflow'),
        ]);
    }

    /**
     * The CP edit URL + label for the Craft element an element-integration
     * dispatch created (#142), or null when the log row carries no element or it
     * has since been deleted.
     *
     * @param array<string, mixed> $log a dispatch-log row
     * @return array{url: string, label: string}|null
     */
    private function elementLink(array $log): ?array
    {
        $elementId = $log['elementId'] ?? null;
        $elementType = $log['elementType'] ?? null;
        if ($elementId === null || !is_string($elementType)
            || !is_subclass_of($elementType, \craft\base\ElementInterface::class)) {
            return null;
        }

        $element = Craft::$app->getElements()->getElementById((int) $elementId, $elementType, '*');
        if ($element === null || ($url = $element->getCpEditUrl()) === null) {
            return null;
        }

        return [
            'url' => $url,
            'label' => sprintf('%s #%d', $element::displayName(), (int) $elementId),
        ];
    }

    /**
     * Submissions analytics: trends, status + spam split, per-form totals, and
     * integration dispatch health. Read-only; gated by viewSubmissions.
     */
    public function actionAnalytics(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $formId = $request->getQueryParam('formId');
        $formId = $formId !== null && $formId !== '' ? (int) $formId : null;

        $allowedRanges = [7, 30, 90];
        $days = (int) $request->getQueryParam('range', 30);
        if (!in_array($days, $allowedRanges, true)) {
            $days = 30;
        }

        $reports = Plugin::getInstance()->getReports();

        return $this->renderTemplate('simple-form/submissions/analytics', [
            'siteId' => $siteId,
            'formId' => $formId,
            'days' => $days,
            'ranges' => $allowedRanges,
            'forms' => Form::find()->siteId($siteId)->orderBy(['title' => SORT_ASC])->all(),
            'stats' => $reports->statusBreakdown($siteId, $formId),
            'spam' => $reports->spamRate($siteId, $formId),
            'perDay' => $reports->submissionsPerDay($siteId, $days, $formId),
            'perForm' => $reports->perFormTotals($siteId),
            'dispatch' => $reports->dispatchHealth(),
            // Rating/opinion numeric stats only apply to a single form's field set.
            'scales' => $formId !== null ? $reports->scaleBreakdown($siteId, $formId) : [],
        ]);
    }

    /**
     * Per-form survey report (#240): per-field response counts, choice-option
     * breakdowns, and rating/scale distributions over the stored submission
     * data. Read-only; gated by the base viewSubmissions permission. Scoped to
     * the current site, with an optional inclusive YYYY-MM-DD date range.
     */
    public function actionReport(int $formId): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = Form::find()->siteId($siteId)->id($formId)->one();
        if (!$form) {
            throw new \yii\web\NotFoundHttpException('Form not found');
        }

        // F17 (CWE-20): only honor well-formed YYYY-MM-DD bounds.
        $dateFrom = $request->getQueryParam('dateFrom');
        $dateFrom = is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom) ? $dateFrom : null;
        $dateTo = $request->getQueryParam('dateTo');
        $dateTo = is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo) ? $dateTo : null;

        $reports = Plugin::getInstance()->getReports();

        return $this->renderTemplate('simple-form/submissions/report', [
            'form' => $form,
            'formId' => $formId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'responses' => $reports->responseCount($siteId, $formId, $dateFrom, $dateTo),
            'report' => $reports->fieldReport($siteId, $formId, $dateFrom, $dateTo),
        ]);
    }

    public function actionView(int $submissionId): Response
    {
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $submission = Submission::find()->siteId($siteId)->id($submissionId)->one();
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
        $integrationNames = array_column($integrations, 'name', 'id');

        // Deep links to elements created by element-integration dispatches (#142),
        // keyed by log id.
        $elementLinks = [];
        foreach ($logs as $log) {
            if (($link = $this->elementLink($log)) !== null) {
                $elementLinks[(int) $log['id']] = $link;
            }
        }

        // Approval workflow (#248): the submission's current stage + the moves
        // the current user may make from it. Empty/null when the workflow is off.
        $workflow = Plugin::getInstance()->getWorkflow();
        $canManageSubmissions = Craft::$app->getUser()->checkPermission(SimpleFormPermissions::MANAGE_SUBMISSIONS);

        return $this->renderTemplate('simple-form/submissions/view', [
            'submission' => $submission,
            'form' => $form,
            'data' => $data,
            'integrationLogs' => $logs,
            'integrationNames' => $integrationNames,
            'elementLinks' => $elementLinks,
            'canManageIntegrations' => Craft::$app->getUser()->checkPermission(SimpleFormPermissions::MANAGE_INTEGRATIONS),
            'pdfAvailable' => Plugin::getInstance()->getPdf()->isAvailable(),
            'workflowEnabled' => $workflow->isEnabled(),
            'workflowStatus' => $workflow->isEnabled() ? $workflow->getStatus((string) $submission->workflowStatus) : null,
            'workflowTransitions' => ($workflow->isEnabled() && $canManageSubmissions)
                ? $workflow->allowedTransitions($submission->workflowStatus, Craft::$app->getUser()->getIdentity())
                : [],
        ]);
    }

    /**
     * Stream a PDF of one submission (#143). Serves the stored Asset when a PDF
     * storage volume is configured, otherwise renders on demand. Gated by the
     * base viewSubmissions permission (enforced in beforeAction). Degrades with a
     * clear error when no PDF engine is installed.
     */
    public function actionPdf(int $submissionId): Response
    {
        $pdf = Plugin::getInstance()->getPdf();
        if (!$pdf->isAvailable()) {
            // Distinguish the two causes so the operator gets the right fix: a
            // missing engine vs. an edition that gates PDF generation.
            $message = $pdf->engineAvailable()
                ? Craft::t('simple-form', 'Submission PDFs require the Pro edition.')
                : Craft::t('simple-form', 'Install the dompdf library to generate submission PDFs.');
            throw new \yii\web\ServerErrorHttpException($message);
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission = Submission::find()->siteId($siteId)->id($submissionId)->one();
        if (!$submission) {
            throw new \yii\web\NotFoundHttpException('Submission not found');
        }

        $form = $submission->getForm();
        if (!$form instanceof Form) {
            throw new \yii\web\NotFoundHttpException('Form not found');
        }

        $data = is_array($submission->data) ? $submission->data : [];
        $filename = $pdf->filename($form, $submission);

        // Reuse a stored Asset when one exists, else render on demand.
        $asset = $pdf->store($form, $submission, $data);
        if ($asset !== null) {
            return $this->response->sendStreamAsFile($asset->getStream(), $filename, [
                'mimeType' => 'application/pdf',
            ]);
        }

        $bytes = $pdf->render($form, $submission, $data, (int) $submission->siteId);
        if ($bytes === null) {
            throw new \yii\web\ServerErrorHttpException(Craft::t('simple-form', 'Couldn’t generate the submission PDF.'));
        }

        return $this->response->sendContentAsFile($bytes, $filename, [
            'mimeType' => 'application/pdf',
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

        $submission = Submission::find()->siteId($siteId)->id($submissionId)->one();
        if (!$submission) {
            return $this->asJsonError(Craft::t('simple-form', 'Couldn’t update the status.'));
        }

        $statuses = SubmissionStatus::all();
        $currentIndex = array_search($submission->readStatus, $statuses);
        $submission->readStatus = $statuses[($currentIndex + 1) % count($statuses)];

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return $this->asJsonError(Craft::t('simple-form', 'Couldn’t update the status.'));
        }

        return $this->asJsonSuccess(['status' => $submission->readStatus]);
    }

    /**
     * Move a submission to a workflow stage (#248). The WorkflowService enforces
     * that the transition is configured and that the current user may perform it
     * (role-gated); an unauthorized/invalid move changes nothing. Redirects back
     * to the submission with a notice.
     */
    public function actionTransition(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $submissionId = (int) $request->getRequiredBodyParam('submissionId');
        $toStatus = (string) $request->getRequiredBodyParam('toStatus');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $submission = Submission::find()->siteId($siteId)->id($submissionId)->one();
        if (!$submission instanceof Submission) {
            throw new \yii\web\NotFoundHttpException('Submission not found');
        }

        $user = Craft::$app->getUser()->getIdentity();
        if (Plugin::getInstance()->getWorkflow()->transition($submission, $toStatus, $user)) {
            Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Submission moved.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t move the submission.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Approve a flagged submission out of the spam queue: set it back to "new"
     * and clear its spam reason (handled in SubmissionService::updateStatus).
     */
    public function actionMarkNotSpam(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $submissionId = (int) $request->getRequiredBodyParam('submissionId');

        if (Plugin::getInstance()->getSubmissionService()->updateStatus($submissionId, SubmissionStatus::NEW)) {
            Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Submission approved.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t update the submission.'));
        }

        return $this->redirectToPostedUrl();
    }

    /**
     * Soft-delete a submission from the spam queue (recoverable via the Trashed
     * source for the retention window).
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $submissionId = (int) $request->getRequiredBodyParam('submissionId');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $submission = Submission::find()->siteId($siteId)->id($submissionId)->one();
        if ($submission && Craft::$app->getElements()->deleteElement($submission)) {
            Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Submission deleted.'));
        } else {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t delete the submission.'));
        }

        return $this->redirectToPostedUrl();
    }
}

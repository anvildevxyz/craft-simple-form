<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use craft\web\UploadedFile;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\fields\FileFieldType;
use fabianhaef\simpleform\models\FormModel;
use fabianhaef\simpleform\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end submission editing (#144). A submitter (anonymous, via a secure
 * tokenized link) or the logged-in owning user re-opens their submission; the
 * edit is re-validated + re-saved through the same
 * {@see \fabianhaef\simpleform\services\SubmissionService} path as a create, so
 * validation, conditional logic, and spam protection behave identically.
 *
 * Distinct from the CP {@see SubmissionsController} (which is permission-gated):
 * this is the public, anonymous-capable edit transport. Authorization is enforced
 * server-side on every action — `allowEditing`, the edit window, and a valid token
 * or matching `userId` — never trusting the client.
 */
class SubmissionEditController extends Controller
{
    /**
     * Editing is reachable by anonymous submitters holding a valid token, so the
     * actions must be allowed without a logged-in user. Authorization is still
     * enforced per-request below (token or owner + window + allowEditing), so this
     * is not an open door. CSRF is enforced on the update POST.
     *
     * @var array<string, int>|bool|int
     */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    public $enableCsrfValidation = true;

    /**
     * Re-validate and re-save an edited submission. Authorization is re-checked
     * after the posted submission id is resolved (TOCTOU-safe: the server-side
     * allowEditing/window/token state is verified before any change), then the
     * edit routes through the shared save core.
     *
     * @throws NotFoundHttpException when the submission does not exist
     * @throws ForbiddenHttpException when the request is not authorized to edit it
     * @throws \yii\base\InvalidConfigException
     */
    public function actionUpdate(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();

        $submission = $this->resolveSubmission((int) $request->getBodyParam('submissionId'));
        $token = (string) $request->getBodyParam('t', '');

        $actor = $this->authorizeOrFail($submission, $token !== '' ? $token : null);

        $form = $submission->getForm();
        if (!$form instanceof Form) {
            throw new NotFoundHttpException('Form not found');
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        // Build the values map, resolving file uploads to asset ids — mirrors
        // SubmissionService::createFromRequest so an edit collects values exactly
        // as a create does.
        $values = [];
        $pendingUploads = [];
        $fileErrors = [];
        foreach ((new FormModel($form))->getFields() as $fieldId => $field) {
            if ($field->getType() === FileFieldType::getType()) {
                $files = UploadedFile::getInstancesByName('field_' . $fieldId);
                $config = $field->getConfig();
                $config['required'] = $field->isRequired();
                $errors = (new FileFieldType($config))->validateUpload($files);
                if ($errors !== []) {
                    $fileErrors['field_' . $fieldId] = $errors;
                }
                $pendingUploads[$fieldId] = ['files' => $files, 'config' => $config];
                $values[$fieldId] = [];
            } else {
                $values[$fieldId] = $request->getBodyParam('field_' . $fieldId);
            }
        }

        if ($fileErrors !== []) {
            return $this->asJson(['success' => false, 'errors' => $fileErrors]);
        }

        $uploadService = $plugin->getAssetUploadService();
        $createdAssetIds = [];
        foreach ($pendingUploads as $fieldId => $info) {
            $values[$fieldId] = $ids = $uploadService->saveUploads($info['files'], $info['config']);
            $createdAssetIds = array_merge($createdAssetIds, $ids);
        }

        $result = $plugin->getSubmissionService()->update($submission, $values, [
            'honeypot' => (string) $request->getBodyParam('__honeypot', ''),
            'captchaToken' => null,
            'actor' => $actor,
        ]);

        // Nothing persisted (validation error or a silent honeypot/blocked-spam
        // drop): don't leave orphaned assets behind.
        if ($result['submission'] === null && $createdAssetIds !== []) {
            $uploadService->deleteAssets(...$createdAssetIds);
        }

        // A real validation error reports the errors; otherwise success — including
        // a silent honeypot/blocked-spam drop, so a bot gets no signal.
        if (!empty($result['errors'])) {
            return $this->asJson(['success' => false, 'errors' => $result['errors']]);
        }

        return $this->asJson(['success' => true, 'message' => $settings->submitMessage]);
    }

    /**
     * Load an existing submission or 404. Searches across sites so an edit link
     * works regardless of the current request's site.
     *
     * @throws NotFoundHttpException
     */
    private function resolveSubmission(int $id): Submission
    {
        if ($id <= 0) {
            throw new NotFoundHttpException('Submission not found');
        }

        $submission = Submission::find()->id($id)->siteId('*')->one();
        if (!$submission instanceof Submission) {
            throw new NotFoundHttpException('Submission not found');
        }

        return $submission;
    }

    /**
     * Re-check edit authorization for the resolved submission and 403 when denied.
     * Returns the actor label ('token' | 'user') for audit attribution.
     *
     * @throws ForbiddenHttpException
     */
    private function authorizeOrFail(Submission $submission, ?string $token): string
    {
        $userId = Craft::$app->getUser()->getId();
        $actor = Plugin::getInstance()->getSubmissionService()->authorizeEdit(
            $submission,
            $token,
            $userId !== null ? (int) $userId : null,
        );

        if ($actor === null) {
            throw new ForbiddenHttpException(Craft::t('simple-form', 'You are not allowed to edit this submission.'));
        }

        return $actor;
    }
}

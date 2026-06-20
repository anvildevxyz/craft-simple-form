<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\web\Request;
use craft\web\UploadedFile;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\events\SubmissionEvent;
use fabianhaef\simpleform\fields\FileFieldType;
use fabianhaef\simpleform\helpers\RateLimiter;
use fabianhaef\simpleform\models\FormModel;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

class SubmissionService extends Component
{
    /**
     * Create a submission from a request.
     *
     * Thin transport adapter: it pulls field values, the honeypot value, and the
     * captcha token out of the request body, then hands everything to
     * {@see self::submit()} — the single, transport-agnostic entry point shared
     * by the front-end controller and the GraphQL mutation. Routing both paths
     * through one method keeps validation, spam protection, events, and email
     * notifications identical regardless of how the submission arrived.
     *
     * @param FormModel|Form|string $form Form instance, element, or handle
     * @param Request|null $request Request object (uses Craft request if null)
     * @return array{submission: Submission|null, errors: array<string, mixed>|null}
     */
    public function createFromRequest($form, ?Request $request = null): array
    {
        if ($request === null) {
            /** @var Request $request */
            $request = Craft::$app->getRequest();
        }

        $formElement = $this->resolveForm($form);
        if (!$formElement instanceof Form) {
            return ['submission' => null, 'errors' => ['form' => ['Form not found']]];
        }

        $formModel = new FormModel($formElement);

        // Pull each field's posted value (field_<id>) out of the request body.
        // File fields are special: their uploads are validated here and turned
        // into Asset ids, which become the field's value.
        $values = [];
        $pendingUploads = [];
        $fileErrors = [];
        foreach ($formModel->getFields() as $fieldId => $field) {
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

        // Reject before creating any asset if a file failed validation.
        if ($fileErrors !== []) {
            return ['submission' => null, 'errors' => $fileErrors];
        }

        // Uploads validated — persist them as assets and use the ids as values.
        $uploadService = Plugin::getInstance()->getAssetUploadService();
        $createdAssetIds = [];
        foreach ($pendingUploads as $fieldId => $info) {
            $ids = $uploadService->saveUploads($info['files'], $info['config']);
            $values[$fieldId] = $ids;
            $createdAssetIds = array_merge($createdAssetIds, $ids);
        }

        $userId = Craft::$app->getUser()->getId();

        $result = $this->submit($formElement, $values, [
            'honeypot' => (string) $request->getBodyParam('__honeypot', ''),
            'captchaToken' => null, // CaptchaService reads the request body itself.
            'userId' => $userId !== null ? (int) $userId : null,
        ]);

        // No row persisted (validation error, honeypot/captcha/spam drop) → don't
        // leave orphaned assets behind.
        if ($result['submission'] === null && $createdAssetIds !== []) {
            $uploadService->deleteAssets(...$createdAssetIds);
        }

        return $result;
    }

    /**
     * Per-visitor-IP abuse throttle, shared by every public submit transport
     * (the front-end controller and the GraphQL mutation) so neither can be used
     * to sidestep the other. Returns true when the caller is already over the
     * configured per-minute limit and the submission should be rejected;
     * otherwise records a hit and returns false.
     *
     * A null/empty IP (e.g. unresolvable behind a proxy) is never throttled —
     * collapsing every such visitor into one shared bucket would let unrelated
     * users lock each other out. Resolve real client IPs via Craft's
     * `trustedHosts`/proxy config so the limit is per-visitor in production.
     */
    public function isRateLimited(?string $ip): bool
    {
        $limit = (int) Plugin::getInstance()->getSettings()->submitRateLimitPerMinute;
        if ($limit <= 0 || $ip === null || $ip === '') {
            return false;
        }

        $key = 'submit:' . $ip;
        if (RateLimiter::isLimited($key, $limit)) {
            return true;
        }

        RateLimiter::hit($key, 60);
        return false;
    }

    /**
     * Single, transport-agnostic submission entry point.
     *
     * Both the front-end SubmitController and the GraphQL submit mutation route
     * through here so validation, spam protection, the before/after events, and
     * the notification email all run identically no matter the channel.
     *
     * Context keys:
     *  - `honeypot` (string): the honeypot field value; a non-empty value is
     *     treated as a bot and silently dropped (no row, no errors).
     *  - `captchaToken` (string|null): an explicit captcha token to verify; null
     *     lets {@see CaptchaService} read it from the current request body.
     *  - `skipCaptcha` (bool): bypass captcha entirely. Set true for trusted
     *     server-to-server channels (e.g. a scoped GraphQL token) where a
     *     browser-issued reCAPTCHA token cannot exist. The honeypot is still
     *     enforced, so this is not a blanket spam bypass.
     *  - `userId` (int|null): the submitting user id, if any.
     *  - `siteId` (int|null): site to attribute the submission to; defaults to
     *     the form's site, then the current site.
     *
     * @param Form $form
     * @param array<int|string, mixed> $values posted values keyed by field id (or `field_<id>`)
     * @param array{honeypot?: string, captchaToken?: ?string, skipCaptcha?: bool, userId?: ?int, siteId?: ?int} $context
     * @return array{submission: Submission|null, errors: array<string, mixed>|null}
     */
    public function submit(Form $form, array $values, array $context = []): array
    {
        // Spam protection + validation + the persisted data payload are produced
        // by the shared core so create and edit can never drift apart.
        $core = $this->processSubmission($form, $values, $context);
        if ($core['result'] !== null) {
            // Honeypot/captcha/blocked-spam drop or a validation error: nothing to
            // persist, return the early result the core decided on.
            return $core['result'];
        }

        /** @var array<string, mixed> $data */
        $data = $core['data'];
        $isSpam = $core['isSpam'];

        // Build + save the submission element.
        $siteId = $context['siteId'] ?? $form->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $submission = new Submission();
        $submission->formId = (int) $form->id;
        $submission->siteId = (int) $siteId;
        $submission->data = $data;
        $submission->userId = isset($context['userId']) ? (int) $context['userId'] : null;
        $submission->readStatus = $isSpam ? SubmissionStatus::SPAM : SubmissionStatus::NEW;
        $submission->spamReason = $isSpam ? 'akismet' : null;

        // Fire the before-save event (same as the Twig path).
        $beforeEvent = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_BEFORE_SUBMISSION_SAVE, $beforeEvent);

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return ['submission' => null, 'errors' => ['submission' => ['Failed to save submission']]];
        }

        // If the form collects payment, create the pending order now (before the
        // after-save dispatch) so integrations + email are withheld until the
        // payment completes. Skipped for spam.
        $awaitingPayment = !$isSpam
            && Plugin::getInstance()->getPayments()->prepare($form, $submission, $data);

        // Fire the after-save event. The integration dispatch listener self-skips
        // while a submission is awaiting payment.
        $afterEvent = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_AFTER_SUBMISSION_SAVE, $afterEvent);

        // Send notifications (notification rows or the legacy email columns;
        // EmailService no-ops when neither is configured). Skipped for spam and
        // while awaiting payment — the email fires once the order completes.
        if (!$isSpam && !$awaitingPayment) {
            Plugin::getInstance()->getEmailService()->sendSubmissionEmail($form, $submission, $data);
        }

        return ['submission' => $submission, 'errors' => null];
    }

    /**
     * Re-validate and re-save an existing submission through the same shared core
     * as {@see self::submit()} (#144). Authorization (token / owner / window /
     * allowEditing) is the caller's responsibility — see {@see self::authorizeEdit()}.
     *
     * The same element is updated in place: its id, dateCreated, siteId and userId
     * are preserved. The after-save event fires with `isNew = false` so integration
     * listeners can distinguish an edit from a create and self-skip if they should
     * not re-dispatch.
     *
     * @param Submission $submission the existing submission to edit
     * @param array<int|string, mixed> $values posted values keyed by field id (or `field_<id>`)
     * @param array{honeypot?: string, captchaToken?: ?string, skipCaptcha?: bool, actor?: string} $context
     * @return array{submission: Submission|null, errors: array<string, mixed>|null}
     * @throws \yii\base\InvalidConfigException
     */
    public function update(Submission $submission, array $values, array $context = []): array
    {
        $form = $submission->getForm();
        if (!$form instanceof Form) {
            return ['submission' => null, 'errors' => ['form' => ['Form not found']]];
        }

        // An edit runs through the identical spam + validation + conditional-logic
        // core as a create, so an edit can never be a spam-laundering bypass.
        $core = $this->processSubmission($form, $values, $context);
        if ($core['result'] !== null) {
            return $core['result'];
        }

        /** @var array<string, mixed> $data */
        $data = $core['data'];
        $isSpam = $core['isSpam'];

        // Preserve id/dateCreated/siteId/userId — only the content + spam state
        // change. A spam verdict on edit flags the submission like a new one.
        $submission->data = $data;
        if ($isSpam) {
            $submission->readStatus = SubmissionStatus::SPAM;
            $submission->spamReason = 'akismet';
        }

        $beforeEvent = new SubmissionEvent($submission, $form, $data, false);
        Plugin::getInstance()->trigger(Plugin::EVENT_BEFORE_SUBMISSION_SAVE, $beforeEvent);

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return ['submission' => null, 'errors' => ['submission' => ['Failed to save submission']]];
        }

        // isNew = false lets integration/notification listeners self-skip an edit.
        $afterEvent = new SubmissionEvent($submission, $form, $data, false);
        Plugin::getInstance()->trigger(Plugin::EVENT_AFTER_SUBMISSION_SAVE, $afterEvent);

        $actor = (string) ($context['actor'] ?? 'token');
        Plugin::getInstance()->getAudit()->log(
            'submission.edit',
            'submission',
            (int) $submission->id,
            'edited via front-end (' . $actor . ')',
        );

        return ['submission' => $submission, 'errors' => null];
    }

    /**
     * Decide whether an edit of $submission is authorized (#144). An edit is
     * allowed iff the form opted into editing AND the edit window is open AND
     * either a valid token is supplied OR the current user owns the submission.
     *
     * Returns the actor label ('token' | 'user') on success, or null when the
     * edit must be refused. Never trusts the client: every gate is server-side.
     */
    public function authorizeEdit(Submission $submission, ?string $token, ?int $currentUserId): ?string
    {
        $form = $submission->getForm();
        if (!$form instanceof Form || !$form->allowEditing) {
            return null;
        }

        // The window is authoritative even when a token's intrinsic expiry is longer.
        $editTokens = Plugin::getInstance()->getSubmissionEditTokens();
        if (!$editTokens->isWithinEditWindow($submission, (int) $form->editWindowMinutes)) {
            return null;
        }

        // A logged-in owner needs no token.
        if ($currentUserId !== null && $submission->userId !== null && $submission->userId === $currentUserId) {
            return 'user';
        }

        // Otherwise a valid, unexpired token for THIS submission is required.
        if ($editTokens->verify($submission, $token)) {
            return 'token';
        }

        return null;
    }

    /**
     * Shared create/edit core: honeypot + captcha + per-field validation +
     * conditional-logic visibility + content spam scoring, producing the persisted
     * `data` payload. Returns either an early `result` (a drop or validation error
     * the caller should return verbatim) or the validated `data` + `isSpam` verdict
     * for the caller to persist. Routing both submit() and update() through here
     * guarantees identical validation/spam/conditional behavior.
     *
     * @param array<int|string, mixed> $values
     * @param array{honeypot?: string, captchaToken?: ?string, skipCaptcha?: bool} $context
     * @return array{result: array{submission: null, errors: array<string, mixed>|null}|null, data: array<string, mixed>, isSpam: bool}
     */
    private function processSubmission(Form $form, array $values, array $context): array
    {
        $settings = Plugin::getInstance()->getSettings();

        // (1) Honeypot — transport-agnostic and always honored. A filled honeypot
        // is a bot: drop it silently (no persisted row, no error surfaced) so the
        // client gets no signal about the trap.
        if ($settings->enableHoneypot && trim((string) ($context['honeypot'] ?? '')) !== '') {
            return ['result' => ['submission' => null, 'errors' => null], 'data' => [], 'isSpam' => false];
        }

        // (2) Captcha — skippable only for explicitly trusted channels.
        if (empty($context['skipCaptcha'])) {
            $token = $context['captchaToken'] ?? null;
            if (!Plugin::getInstance()->getCaptchaService()->verify($token)) {
                return [
                    'result' => ['submission' => null, 'errors' => ['captcha' => ['Captcha verification failed']]],
                    'data' => [],
                    'isSpam' => false,
                ];
            }
        }

        $formModel = new FormModel($form);

        // (3) Resolve every field's value up front, keyed by field handle, so
        // conditional rules can be evaluated against the complete submitted
        // snapshot (a field's visibility may depend on any other field).
        $valuesByHandle = [];
        foreach ($formModel->getFields() as $fieldId => $field) {
            $valuesByHandle[$field->getName()] = $this->valueForField($values, (int) $fieldId);
        }

        // (4) Validate every visible field and build the persisted data payload.
        // Fields hidden by conditional logic are neither validated nor stored —
        // a hidden field's posted value is never trusted (so a crafted POST
        // cannot inject data the visitor never saw), and a hidden required
        // field cannot block submission.
        $data = [];
        $errors = [];

        foreach ($formModel->getFields() as $fieldId => $field) {
            if (!$field->isVisible($valuesByHandle)) {
                continue;
            }

            $value = $valuesByHandle[$field->getName()];

            $fieldErrors = $field->validateValue($value, $valuesByHandle);
            if (!empty($fieldErrors)) {
                $errors['field_' . $fieldId] = $fieldErrors;
            }

            $data['field_' . $fieldId] = [
                'label' => $field->getLabel() ?? $field->getName(),
                'type' => $field->getType(),
                'value' => $value,
            ];
        }

        if (!empty($errors)) {
            return ['result' => ['submission' => null, 'errors' => $errors], 'data' => [], 'isSpam' => false];
        }

        // (5) Content spam scoring (Akismet). A spam verdict either drops the
        // submission silently (block — like the honeypot, no signal to the bot)
        // or saves it flagged as spam for review (flag, the default).
        $isSpam = Plugin::getInstance()->getAkismetService()->isSpam($form, $data);
        if ($isSpam && $settings->akismetMode === Settings::AKISMET_BLOCK) {
            return ['result' => ['submission' => null, 'errors' => null], 'data' => [], 'isSpam' => true];
        }

        return ['result' => null, 'data' => $data, 'isSpam' => $isSpam];
    }

    public function getSubmission(int $submissionId): ?Submission
    {
        return Submission::find()->id($submissionId)->one();
    }

    public function updateStatus(int $submissionId, string $status): bool
    {
        if (!SubmissionStatus::isValid($status)) {
            return false;
        }

        $submission = $this->getSubmission($submissionId);
        if (!$submission) {
            return false;
        }

        $submission->readStatus = $status;
        // Keep spamReason in step with the status: marking spam by hand records
        // 'manual' (unless Akismet already set a reason); moving out of spam
        // (e.g. "Mark as not spam") clears it.
        if ($status === SubmissionStatus::SPAM) {
            $submission->spamReason ??= 'manual';
        } else {
            $submission->spamReason = null;
        }

        $saved = Craft::$app->getElements()->saveElement($submission);
        if ($saved) {
            Plugin::getInstance()->getAudit()->log('submission.status', 'submission', $submissionId, 'status → ' . $status);
        }
        return $saved;
    }

    /**
     * Resolve a form handle/element/model to a Form element.
     *
     * @param FormModel|Form|string $form
     */
    private function resolveForm($form): ?Form
    {
        if ($form instanceof Form) {
            return $form;
        }

        if ($form instanceof FormModel) {
            $id = $form->getId();
            return $id !== null ? Form::find()->id($id)->one() : null;
        }

        return Form::find()
            ->handle($form)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();
    }

    /**
     * Read a field's value from a posted map keyed by either the bare field id
     * (123) or the prefixed input name (`field_123`).
     *
     * @param array<int|string, mixed> $values
     */
    private function valueForField(array $values, int $fieldId): mixed
    {
        if (array_key_exists($fieldId, $values)) {
            return $values[$fieldId];
        }

        return $values['field_' . $fieldId] ?? null;
    }
}

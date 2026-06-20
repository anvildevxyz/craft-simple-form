<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\helpers\Db;
use craft\web\Request;
use craft\web\UploadedFile;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\events\SubmissionEvent;
use fabianhaef\simpleform\fields\EmailFieldType;
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
        $settings = Plugin::getInstance()->getSettings();

        // (1) Honeypot — transport-agnostic and always honored. A filled honeypot
        // is a bot: drop it silently (no persisted row, no error surfaced) so the
        // client gets no signal about the trap.
        if ($settings->enableHoneypot && trim((string) ($context['honeypot'] ?? '')) !== '') {
            return ['submission' => null, 'errors' => null];
        }

        // (1b) Access gates (#135). Enforced here so every transport (AJAX, no-JS
        // POST, GraphQL) shares one code path — a crafted POST cannot bypass the
        // template-level guards in TwigExtension::renderForm. Runs after the
        // honeypot (bots get no signal) and before captcha/validation.
        $userId = isset($context['userId']) ? (int) $context['userId'] : null;

        // Require login: reject anonymous submissions outright.
        if ($form->requireLogin && $userId === null) {
            return ['submission' => null, 'errors' => ['form' => [$form->getLoginRequiredMessage()]]];
        }

        // Per-user limit: block a user at/over their cap. Guests are only limited
        // when the form opts into a guest key (email); spam rows never count.
        if ($form->submissionsPerUser !== null
            && $this->userSubmissionCount($form, $userId, $values) >= $form->submissionsPerUser) {
            return ['submission' => null, 'errors' => ['form' => [$form->getUserLimitMessage()]]];
        }

        // (2) Captcha — skippable only for explicitly trusted channels.
        if (empty($context['skipCaptcha'])) {
            $token = $context['captchaToken'] ?? null;
            if (!Plugin::getInstance()->getCaptchaService()->verify($token)) {
                return ['submission' => null, 'errors' => ['captcha' => ['Captcha verification failed']]];
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
            return ['submission' => null, 'errors' => $errors];
        }

        // (5) Content spam scoring (Akismet). A spam verdict either drops the
        // submission silently (block — like the honeypot, no signal to the bot)
        // or saves it flagged as spam for review (flag, the default).
        $isSpam = Plugin::getInstance()->getAkismetService()->isSpam($form, $data);
        if ($isSpam && Plugin::getInstance()->getSettings()->akismetMode === Settings::AKISMET_BLOCK) {
            return ['submission' => null, 'errors' => null];
        }

        // (6) Build + save the submission element.
        $siteId = $context['siteId'] ?? $form->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $submission = new Submission();
        $submission->formId = (int) $form->id;
        $submission->siteId = (int) $siteId;
        $submission->data = $data;
        // Always associate the submission with the logged-in user (#135).
        $submission->userId = $userId;
        $submission->readStatus = $isSpam ? SubmissionStatus::SPAM : SubmissionStatus::NEW;
        $submission->spamReason = $isSpam ? 'akismet' : null;

        // (7) Fire the before-save event (same as the Twig path).
        $beforeEvent = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_BEFORE_SUBMISSION_SAVE, $beforeEvent);

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return ['submission' => null, 'errors' => ['submission' => ['Failed to save submission']]];
        }

        // (8) If the form collects payment, create the pending order now (before
        // the after-save dispatch) so integrations + email are withheld until the
        // payment completes. Skipped for spam.
        $awaitingPayment = !$isSpam
            && Plugin::getInstance()->getPayments()->prepare($form, $submission, $data);

        // (9) Fire the after-save event. The integration dispatch listener
        // self-skips while a submission is awaiting payment.
        $afterEvent = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_AFTER_SUBMISSION_SAVE, $afterEvent);

        // (10) Send notifications (notification rows or the legacy email columns;
        // EmailService no-ops when neither is configured). Skipped for spam and
        // while awaiting payment — the email fires once the order completes.
        if (!$isSpam && !$awaitingPayment) {
            Plugin::getInstance()->getEmailService()->sendSubmissionEmail($form, $submission, $data);
        }

        return ['submission' => $submission, 'errors' => null];
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
     * Whether a logged-in user has already hit the form's per-user submission cap.
     *
     * Used by the public render (TwigExtension) to show the limit message instead
     * of the form. Guests are never pre-blocked at render time — there is no
     * posted value to key on yet — but the server still enforces guest keying on
     * submit. Returns false when the form has no per-user cap.
     */
    public function userHasReachedLimit(Form $form, ?int $userId): bool
    {
        if ($form->submissionsPerUser === null || $userId === null) {
            return false;
        }

        return $this->userSubmissionCount($form, $userId, []) >= $form->submissionsPerUser;
    }

    /**
     * Count prior, non-spam submissions that count toward a submitter's per-user
     * allowance for the given form.
     *
     * Logged-in users key on their stable `userId`. Guests are keyed only when the
     * form opts in: `email` matches the submitted value of the form's first email
     * field; `none` (and `ip`, reserved until an IP column exists) never limits a
     * guest, so the cap simply does not apply.
     *
     * @param array<int|string, mixed> $values posted values keyed by field id (or `field_<id>`)
     */
    private function userSubmissionCount(Form $form, ?int $userId, array $values): int
    {
        // Count every status except spam: a spam row must never burn a user's
        // allowance. status(null) keeps soft-delete handling; the readStatus
        // filter then excludes spam.
        $query = Submission::find()
            ->formId((int) $form->id)
            ->siteId('*')
            ->status(null)
            ->andWhere(['not', ['[[simpleform_submissions.readStatus]]' => SubmissionStatus::SPAM]]);

        if ($userId !== null) {
            $query->userId($userId);

            return (int) $query->count();
        }

        if ($form->guestLimitKey === Form::GUEST_LIMIT_EMAIL) {
            $email = $this->guestEmailValue($form, $values);
            if ($email === null) {
                return 0;
            }

            // Best-effort guest dedup: count prior submissions whose stored email
            // field value matches. Documented as advisory, not a security control.
            return (int) $query
                ->andWhere(Db::parseParam('simpleform_submissions.userId', ':empty:'))
                ->andWhere(['like', '[[simpleform_submissions.data]]', $email])
                ->count();
        }

        // 'none' (and the reserved 'ip' key) never limit guests.
        return 0;
    }

    /**
     * Resolve the submitted value of the form's first email-type field, used to
     * key the per-user limit for guests. Returns null when the form has no email
     * field or the value is blank.
     *
     * @param array<int|string, mixed> $values posted values keyed by field id (or `field_<id>`)
     */
    private function guestEmailValue(Form $form, array $values): ?string
    {
        $formModel = new FormModel($form);

        foreach ($formModel->getFields() as $fieldId => $field) {
            if ($field->getType() !== EmailFieldType::getType()) {
                continue;
            }

            $value = $this->valueForField($values, (int) $fieldId);
            $value = is_string($value) ? trim($value) : '';

            return $value !== '' ? $value : null;
        }

        return null;
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

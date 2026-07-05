<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\events\BeforeValidateSubmissionEvent;
use anvildev\simpleform\events\SubmissionEvent;
use anvildev\simpleform\fields\CalculationFieldType;
use anvildev\simpleform\fields\CompositeFieldType;
use anvildev\simpleform\fields\EmailFieldType;
use anvildev\simpleform\fields\FileFieldType;
use anvildev\simpleform\fields\HiddenFieldType;
use anvildev\simpleform\fields\RepeaterFieldType;
use anvildev\simpleform\fields\SignatureFieldType;
use anvildev\simpleform\helpers\ConditionalEvaluator;
use anvildev\simpleform\helpers\JumpResolver;
use anvildev\simpleform\helpers\RateLimiter;
use anvildev\simpleform\helpers\SafeUrl;
use anvildev\simpleform\helpers\SignaturePng;
use anvildev\simpleform\models\FieldModel;
use anvildev\simpleform\models\FormModel;
use anvildev\simpleform\models\Settings;
use anvildev\simpleform\Plugin;
use Carbon\Carbon;
use Craft;
use craft\elements\Entry;
use craft\helpers\Db;
use craft\web\Request;
use craft\web\UploadedFile;
use yii\base\Component;

/**
 * @phpstan-type SubmissionResult array{submission: Submission|null, errors: array<string, mixed>|null, data?: array<string, mixed>, paymentRedirectUrl?: string}
 * @phpstan-type SubmissionContext array{honeypot?: string, captchaToken?: ?string, skipCaptcha?: bool, userId?: ?int, siteId?: ?int, payment?: array<string, mixed>, couponCode?: string, actor?: string, _isEdit?: bool, attribution?: array<string, string>|null, partialToken?: string}
 */
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
     * @return SubmissionResult
     */
    public function createFromRequest(FormModel|Form|string $form, ?Request $request = null): array
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
        // File and Signature fields are special: their uploads / decoded PNG data
        // URLs are validated here and turned into Asset ids, which become the
        // field's value.
        $values = [];
        $pendingUploads = [];
        $pendingSignatures = [];
        $tempFiles = [];
        $fileErrors = [];
        foreach ($formModel->getFields() as $fieldId => $field) {
            // Presentational/layout blocks (heading, divider, html) capture no
            // value: never collect a posted value for them, so a crafted
            // field_<id> POST against a layout block is ignored.
            if (!$field->isInputType()) {
                continue;
            }

            $config = $field->getConfig();
            $config['required'] = $field->isRequired();

            if ($field->getType() === FileFieldType::getType()) {
                $files = UploadedFile::getInstancesByName('field_' . $fieldId);
                $errors = (new FileFieldType($config))->validateUpload($files);
                if ($errors !== []) {
                    $fileErrors['field_' . $fieldId] = $errors;
                }
                $pendingUploads[$fieldId] = ['files' => $files, 'config' => $config];
                $values[$fieldId] = [];
            } elseif ($field->getType() === SignatureFieldType::getType()) {
                // The signature posts as a PNG data URL string. Validate it here
                // (required + decodable PNG) before any temp file is written.
                $dataUrl = $request->getBodyParam('field_' . $fieldId);
                $errors = (new SignatureFieldType($config))->validate($dataUrl);
                if ($errors !== []) {
                    $fileErrors['field_' . $fieldId] = $errors;
                }
                $pendingSignatures[$fieldId] = ['dataUrl' => $dataUrl, 'config' => $config];
                $values[$fieldId] = [];
            } else {
                $values[$fieldId] = $request->getBodyParam('field_' . $fieldId);
            }
        }

        // Reject before creating any asset if a file/signature failed validation.
        // No temp files exist yet (signatures are decoded only past this gate).
        if ($fileErrors !== []) {
            return ['submission' => null, 'errors' => $fileErrors];
        }

        // Uploads validated — persist them as assets and use the ids as values.
        $uploadService = Plugin::getInstance()->getAssetUploadService();
        $createdAssetIds = [];
        foreach ($pendingUploads as $fieldId => $info) {
            $ids = $uploadService->saveUploads($info['files'], $info['config']);
            $values[$fieldId] = $ids;
            array_push($createdAssetIds, ...$ids);
        }

        // Signatures: decode each validated data URL to a temp PNG, then save it
        // through the same asset pipeline so the id list becomes the field value.
        foreach ($pendingSignatures as $fieldId => $info) {
            $bytes = SignaturePng::decode($info['dataUrl']);
            if ($bytes === null) {
                // Empty/optional signature → no asset, empty value.
                continue;
            }
            $tempPath = $this->writeSignatureTempFile($bytes);
            if ($tempPath === null) {
                continue;
            }
            $tempFiles[] = $tempPath;
            $ids = $uploadService->saveTempFiles(
                [['path' => $tempPath, 'filename' => 'signature-' . $fieldId . '-' . time() . '.png']],
                $info['config'],
            );
            $values[$fieldId] = $ids;
            array_push($createdAssetIds, ...$ids);
        }

        $userId = Craft::$app->getUser()->getId();

        // Gateway payment-form fields (card number/expiry/cvv, posted under the
        // `paymentForm` namespace by the embedded gateway form) drive the
        // pay-to-submit charge (#116). Absent for non-payment forms.
        $paymentForm = $request->getBodyParam('paymentForm');

        $result = $this->submit($formElement, $values, [
            'honeypot' => (string) $request->getBodyParam('__honeypot', ''),
            'captchaToken' => null, // CaptchaService reads the request body itself.
            'userId' => $userId !== null ? (int) $userId : null,
            'payment' => is_array($paymentForm) ? $paymentForm : [],
            // Optional discount code applied to the payment amount (#246).
            'couponCode' => (string) $request->getBodyParam('couponCode', ''),
            // UTM/referrer auto-capture (#249): only persisted when the form opted in.
            'attribution' => $this->parseAttribution($request),
            // Passive partial capture (#242): the token of the partial this submit
            // completes, so it can be deleted (one Submission, zero partials).
            'partialToken' => (string) $request->getBodyParam('partialToken', ''),
        ]);

        // Temp PNGs are copied into the volume by saveTempFiles(); remove the
        // staging files regardless of outcome.
        $this->cleanupTempFiles($tempFiles);

        // No row persisted (validation error, honeypot/captcha/spam drop) → don't
        // leave orphaned assets behind.
        if ($result['submission'] === null && $createdAssetIds !== []) {
            $uploadService->deleteAssets(...$createdAssetIds);
        }

        return $result;
    }

    /**
     * Write decoded signature PNG bytes to a uniquely named temp file, returning
     * its path or null if the file can't be written.
     */
    private function writeSignatureTempFile(string $bytes): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'sfsig');
        if ($path === false) {
            return null;
        }
        if (file_put_contents($path, $bytes) === false) {
            @unlink($path);
            return null;
        }
        return $path;
    }

    /**
     * Remove the staging temp files written for signature decoding.
     *
     * @param list<string> $paths
     */
    private function cleanupTempFiles(array $paths): void
    {
        foreach ($paths as $path) {
            if ($path !== '' && is_file($path)) {
                @unlink($path);
            }
        }
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
     * Throttle the public coupon-preview endpoint (#246) on its own bucket — kept
     * separate from the submit limiter so previewing codes never eats a visitor's
     * submission quota, and always on (independent of submitRateLimitPerMinute) to
     * discourage code enumeration. A null/empty IP is not throttled, mirroring the
     * submit limiter's reasoning.
     */
    public function isCouponRateLimited(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }

        $key = 'coupon:' . $ip;
        if (RateLimiter::isLimited($key, 30)) {
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
     * @param SubmissionContext $context
     * @return SubmissionResult
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
        $spamReason = $core['spamReason'] ?? ($isSpam ? 'akismet' : null);

        // Pay-to-submit (#116): for a form that collects payment, the charge is
        // attempted BEFORE the submission is persisted. A decline/misconfig
        // returns an error and saves nothing; success or an offsite redirect
        // carries the resulting payment state onto the new row. Skipped for spam.
        $payment = null;
        if (!$isSpam) {
            $paymentParams = is_array($context['payment'] ?? null) ? $context['payment'] : [];
            $couponCode = isset($context['couponCode']) ? (string) $context['couponCode'] : '';
            $payment = Plugin::getInstance()->getPayments()->authorizeForSubmit($form, $data, $paymentParams, $couponCode !== '' ? $couponCode : null);
            if ($payment !== null && $payment['error'] !== null) {
                return ['submission' => null, 'errors' => ['payment' => [$payment['error']]]];
            }
        }

        // Build + save the submission element.
        $siteId = $context['siteId'] ?? $form->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $submission = new Submission();
        $submission->formId = (int) $form->id;
        $submission->siteId = (int) $siteId;
        $submission->data = $data;
        // Always associate the submission with the logged-in user (#135).
        $submission->userId = isset($context['userId']) ? (int) $context['userId'] : null;
        $submission->readStatus = $isSpam ? SubmissionStatus::SPAM : SubmissionStatus::NEW;
        $submission->spamReason = $spamReason;
        $submission->sourceIp = $this->sourceIp();

        // Approval workflow (#248): a genuine (non-spam) submission enters the
        // owner-defined pipeline at its initial stage. No-op when disabled.
        if (!$isSpam) {
            Plugin::getInstance()->getWorkflow()->applyInitialStatus($submission);
        }

        // Stamp the payment state before the after-save event so the existing
        // gating (integrations self-skip + email below) keys off it: a settled
        // payment releases immediately, a pending one is withheld until the order
        // completes (handled by PaymentsService::handleOrderCompleted).
        if ($payment !== null && $payment['error'] === null && $payment['status'] !== '') {
            $submission->paymentStatus = $payment['status'];
            $submission->paymentAmount = (string) $payment['amount'];
            $submission->orderId = $payment['orderId'] ?: null;
            // Applied discount code (#246), recorded for the submission record.
            $submission->couponCode = $payment['couponCode'];
            $submission->discountAmount = $payment['discount'] > 0 ? (string) $payment['discount'] : null;
        }

        // Quiz scoring (#241): compute once, here, so the stored score is stable
        // even if the answer key changes later. No-op unless the form is a quiz.
        $this->applyQuizScore($submission, $form, $data, (int) $siteId);

        // UTM/referrer auto-capture (#249): persist the captured attribution only
        // for forms that opted in, so a forged `__sf_attr` POST to a plain form is
        // ignored. First-touch only — captured at create, never on edit.
        if ($form->autoCaptureAttribution && is_array($context['attribution'] ?? null) && $context['attribution'] !== []) {
            $submission->attribution = $context['attribution'];
        }

        // Fire the before-save event (same as the Twig path).
        $beforeEvent = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_BEFORE_SUBMISSION_SAVE, $beforeEvent);

        if (!Craft::$app->getElements()->saveElement($submission)) {
            return ['submission' => null, 'errors' => ['submission' => ['Failed to save submission']]];
        }

        $awaitingPayment = $submission->isAwaitingPayment();

        // Passive partial capture (#242): completing a captured attempt removes
        // its partial, so a completed submission yields exactly one Submission and
        // zero partials. Best-effort — an unknown/empty token is a no-op.
        $partialToken = (string) ($context['partialToken'] ?? '');
        if ($partialToken !== '') {
            Plugin::getInstance()->getDrafts()->delete($partialToken);
        }

        // Fire the after-save event. The integration dispatch listener self-skips
        // while a submission is awaiting payment.
        $afterEvent = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_AFTER_SUBMISSION_SAVE, $afterEvent);

        // Send notifications (queued so a PDF render / upload reads run off-request;
        // #143). Skipped for spam and while awaiting payment — the email fires once
        // the order completes.
        if (!$isSpam && !$awaitingPayment) {
            Plugin::getInstance()->getEmailService()->queueForSubmission($form, $submission, $data);
        }

        // `data` is returned so post-submit resolution (the success message and a
        // templated redirect URL) can interpolate the submitted values without
        // re-reading the persisted row. `paymentRedirectUrl`, when present, sends
        // the visitor on to complete an offsite/3DS payment.
        $result = ['submission' => $submission, 'errors' => null, 'data' => $data];
        if ($payment !== null && ($payment['redirectUrl'] ?? null) !== null) {
            $result['paymentRedirectUrl'] = $payment['redirectUrl'];
        }

        return $result;
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
     * @param SubmissionContext $context
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
        $context['_isEdit'] = true;
        $core = $this->processSubmission($form, $values, $context);
        if ($core['result'] !== null) {
            return $core['result'];
        }

        /** @var array<string, mixed> $data */
        $data = $core['data'];
        $isSpam = $core['isSpam'];
        $spamReason = $core['spamReason'] ?? ($isSpam ? 'akismet' : null);

        // Preserve id/dateCreated/siteId/userId — only the content + spam state
        // change. A spam verdict on edit flags the submission like a new one.
        $submission->data = $data;
        if ($isSpam) {
            $submission->readStatus = SubmissionStatus::SPAM;
            $submission->spamReason = $spamReason;
        }

        // Re-score the edited answers against the current key (#241). The "no
        // retroactive rescore" guarantee is about untouched submissions — an
        // edit deliberately rewrites this row's content, so its score follows.
        $this->applyQuizScore($submission, $form, $data, (int) $submission->siteId);

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
            AuditService::ACTION_SUBMISSION_EDIT,
            AuditService::TARGET_SUBMISSION,
            (int) $submission->id,
            'edited via front-end (' . $actor . ')',
        );

        return ['submission' => $submission, 'errors' => null];
    }

    /**
     * The field handles on steps the logic-jump path skips for these answers
     * (#245), as a set. Empty when the form isn't multi-step/conversational or
     * has no jumps. Uses {@see JumpResolver} — the same rules + sequence the
     * front-end navigator reads — so the server and the visitor agree on the
     * path, and a jumped-over required field can't block the submission.
     *
     * @param array<string, mixed> $valuesByHandle
     * @return array<string, true>
     */
    private function unreachableJumpHandles(Form $form, FormModel $formModel, array $valuesByHandle): array
    {
        $fields = [];
        $configByHandle = [];
        foreach ($formModel->getFields() as $field) {
            $handle = $field->getName();
            $config = $field->getConfig();
            $fields[] = ['name' => $handle, 'config' => $config, 'type' => $field->getType()];
            $configByHandle[$handle] = $config;
        }

        $sequence = JumpResolver::stepSequence(
            $fields,
            $form->renderMode === 'conversational',
            Plugin::getInstance()->getFieldTypeRegistry()->layoutTypeHandles(),
        );
        if (count($sequence) <= 1) {
            return [];
        }

        $stepJumps = JumpResolver::buildStepRules($sequence, $configByHandle);
        $reachable = array_fill_keys(JumpResolver::reachable($stepJumps, count($sequence), $valuesByHandle), true);

        $unreachable = [];
        foreach ($sequence as $i => $handles) {
            if (!isset($reachable[$i])) {
                foreach ($handles as $handle) {
                    $unreachable[$handle] = true;
                }
            }
        }

        return $unreachable;
    }

    /**
     * Stamp the quiz score onto a submission before it is saved (#241). A no-op
     * unless the form opted into quiz mode; otherwise the raw score, max,
     * percentage and grade band are computed from the validated `$data` against
     * the form's current answer key.
     *
     * @param array<string, mixed> $data the persisted submission data payload
     */
    private function applyQuizScore(Submission $submission, Form $form, array $data, int $siteId): void
    {
        if (!$form->quizMode) {
            return;
        }

        $result = Plugin::getInstance()->getQuizScoring()->scoreSubmission($form, $data, $siteId);
        $submission->quizScore = $result['score'];
        $submission->quizMaxScore = $result['maxScore'];
        $submission->quizPercentage = $result['percentage'];
        $submission->quizGrade = $result['grade'];
    }

    /**
     * Read and sanitize the marketing-attribution payload posted under the
     * `__sf_attr` group by the front-end capture script (#249). Only the known
     * keys are kept; each value is trimmed, length-bounded, and stripped of
     * control characters; empty values are dropped. Returns the non-empty map or
     * null when nothing was captured. The value is only *persisted* for forms
     * that opted in (gated in {@see self::submit()}), so this never trusts the
     * client beyond a bounded, opt-in plain-text record.
     *
     * @return array<string, string>|null
     */
    private function parseAttribution(Request $request): ?array
    {
        $raw = $request->getBodyParam('__sf_attr');
        if (!is_array($raw)) {
            return null;
        }

        // referrer/landing_page are URLs (longer); the utm_* are short tags.
        $maxLengths = [
            'utm_source' => 255,
            'utm_medium' => 255,
            'utm_campaign' => 255,
            'utm_term' => 255,
            'utm_content' => 255,
            'referrer' => 2048,
            'landing_page' => 2048,
        ];

        $out = [];
        foreach ($maxLengths as $key => $max) {
            $value = $raw[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $value = preg_replace('/[\x00-\x1F\x7F]/u', '', trim($value)) ?? '';
            $value = mb_substr($value, 0, $max);
            if ($value !== '') {
                $out[$key] = $value;
            }
        }

        return $out !== [] ? $out : null;
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
     * @param SubmissionContext $context
     * @return array{result: array{submission: null, errors: array<string, mixed>|null}|null, data: array<string, mixed>, isSpam: bool, spamReason?: ?string}
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

        // (1b) Scheduling window + quota — enforced here so the AJAX path, the
        // no-JS POST path, and the GraphQL mutation are all rejected by one
        // check (a crafted POST or a stale cached page cannot sneak past the
        // rendered form). Placed after the honeypot so bots still get no signal,
        // but before captcha/validation so a closed form does no extra work.
        // The cap is a soft business limit; see Form::getSubmissionCount() for
        // the documented race-safety note.
        if (!$form->isAcceptingSubmissions()) {
            return [
                'result' => ['submission' => null, 'errors' => ['form' => [$form->getResolvedClosedMessage()]]],
                'data' => [],
                'isSpam' => false,
            ];
        }

        // (1c) Access gates (#135). Enforced here so every transport (AJAX, no-JS
        // POST, GraphQL) shares one code path — a crafted POST cannot bypass the
        // template-level guards in TwigExtension::renderForm. Runs after the
        // honeypot (bots get no signal) and before captcha/validation.
        $userId = isset($context['userId']) ? (int) $context['userId'] : null;

        // Require login: reject anonymous submissions outright.
        if ($form->requireLogin && $userId === null) {
            return [
                'result' => ['submission' => null, 'errors' => ['form' => [$form->getLoginRequiredMessage()]]],
                'data' => [],
                'isSpam' => false,
            ];
        }

        // Per-user limit: block a user at/over their cap. Guests are only limited
        // when the form opts into a guest key (email); spam rows never count.
        if ($form->submissionsPerUser !== null
            && $this->userSubmissionCount($form, $userId, $values) >= $form->submissionsPerUser) {
            return [
                'result' => ['submission' => null, 'errors' => ['form' => [$form->getUserLimitMessage()]]],
                'data' => [],
                'isSpam' => false,
            ];
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
            if (!$field->isInputType()) {
                continue;
            }
            $valuesByHandle[$field->getName()] = $this->valueForField($values, (int) $fieldId);
        }

        // (3b) Let third parties normalize or augment the resolved values before
        // validation, conditional evaluation and storage all read them. Fires on
        // every channel (front-end, GraphQL, MCP) and for both creates and edits.
        $plugin = Plugin::getInstance();
        if ($plugin !== null && $plugin->hasEventHandlers(Plugin::EVENT_BEFORE_VALIDATE)) {
            $event = new BeforeValidateSubmissionEvent([
                'form' => $form,
                'values' => $values,
                'valuesByHandle' => $valuesByHandle,
                'context' => $context,
                'isNew' => empty($context['_isEdit']),
            ]);
            $plugin->trigger(Plugin::EVENT_BEFORE_VALIDATE, $event);
            $valuesByHandle = $event->valuesByHandle;
        }

        // (3c) Logic jumps (#245): replay the jump path server-side from the same
        // rules the navigator used, so a field on a step the answers jumped over
        // is treated exactly like a hidden field — not validated, not stored, and
        // unable to block submission. Mirrors the front-end SF.jumps resolution.
        $unreachableHandles = $this->unreachableJumpHandles($form, $formModel, $valuesByHandle);

        // (4) Validate every visible field and build the persisted data payload.
        // Fields hidden by conditional logic are neither validated nor stored —
        // a hidden field's posted value is never trusted (so a crafted POST
        // cannot inject data the visitor never saw), and a hidden required
        // field cannot block submission.
        $data = [];
        $errors = [];

        // Site the submission is attributed to; passed to fields whose persisted
        // value depends on the site (e.g. the Consent record's localized snapshot).
        $siteId = $context['siteId'] ?? $form->siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        foreach ($formModel->getFields() as $fieldId => $field) {
            // Layout blocks are never validated and never written to
            // submission.data — no phantom field_<id> entry, no column, no
            // validation error even if a `required` config is forged.
            if (!$field->isInputType()) {
                continue;
            }

            if (!$field->isVisible($valuesByHandle)) {
                continue;
            }

            // A field on a step the jump path skipped is not part of this
            // submission — treat it like a hidden field (#245).
            if (isset($unreachableHandles[$field->getName()])) {
                continue;
            }

            $value = $valuesByHandle[$field->getName()];

            // Hidden fields (#124) are captured from a configured source, not
            // typed by the visitor. Re-resolve server-side: `user` sources
            // ignore the posted value entirely (anti-spoofing), and
            // static/query/cookie values are sanitized to bounded plain text.
            if ($field->getType() === HiddenFieldType::getType()) {
                $value = (new HiddenFieldType($field->getConfig()))
                    ->resolveForSubmit($value, ['userId' => $context['userId'] ?? null]);
            }

            $fieldErrors = $field->validateValue($value, $valuesByHandle);
            if (!empty($fieldErrors)) {
                $errors['field_' . $fieldId] = $fieldErrors;
            }

            // A repeater's posted value is a nested array keyed by row index and
            // inner handle; normalize it to an ordered list of row objects so the
            // stored shape matches the validated one (unknown inner keys dropped,
            // empty trailing rows removed, gaps re-keyed).
            if ($field->getType() === RepeaterFieldType::getType()) {
                $repeater = new RepeaterFieldType($field->getConfig());
                $value = RepeaterFieldType::normalizeRows($value, $repeater->innerFields());
            }

            // Composite fields (Name/Address) store an associative sub-part map
            // limited to their enabled sub-keys, so a crafted POST cannot inject
            // keys the field never rendered.
            $value = $this->serializeFieldValue($field, $value);

            // Let the field type shape its persisted value (identity for most;
            // the Consent field stamps an auditable record here). Skipped when the
            // field already failed validation — the submission won't be saved.
            $persisted = empty($fieldErrors)
                ? $field->persistValue($value, ['siteId' => (int) $siteId])
                : $value;

            // Persist the field type's normalized shape (a passthrough for most
            // types; e.g. Phone stores a {raw, e164, country} map) so exports and
            // integrations get the canonical value on both transports.
            $storedValue = $this->normalizedValueForField($field, $persisted);

            // Coerce to the field type's canonical storage form (e.g. an int for
            // rating/opinion) so analytics and the exporter treat the column
            // numerically rather than as a string.
            $storedValue = $field->normalizeValue($storedValue);

            $data['field_' . $fieldId] = [
                'label' => $field->getLabel() ?? $field->getName(),
                'type' => $field->getType(),
                'value' => $storedValue,
            ];
        }

        if (!empty($errors)) {
            return ['result' => ['submission' => null, 'errors' => $errors], 'data' => [], 'isSpam' => false];
        }

        // (4b) Authoritative server-side recompute of every calculation field
        // (#131). Runs after ordinary fields are resolved so references are
        // populated, and re-inserts each result into $valuesByHandle so a later
        // calculation (or a linked Payment field's amountField) reads the server
        // truth — never the client-posted value, which is discarded. Fields
        // hidden by conditional logic are skipped, so they neither compute nor
        // store, exactly like ordinary fields.
        $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();
        foreach ($formModel->getFields() as $fieldId => $field) {
            if ($field->getType() !== CalculationFieldType::getType()) {
                continue;
            }
            if (!$field->isVisible($valuesByHandle)) {
                continue;
            }

            /** @var CalculationFieldType $fieldType */
            $fieldType = $fieldTypeRegistry->getFieldType(CalculationFieldType::getType(), $field->getConfig());
            $result = $fieldType->compute($valuesByHandle);
            $valuesByHandle[$field->getName()] = $result;

            $data['field_' . $fieldId] = [
                'label' => $field->getLabel() ?? $field->getName(),
                'type' => CalculationFieldType::getType(),
                'value' => $result,
                'display' => $fieldType->format($result),
            ];
        }

        // (5a) Deterministic denylists (#140): blocked keywords, emails/domains,
        // and IPs/CIDR ranges. A hit either drops the submission silently (block —
        // like the honeypot) or flags it as spam for review (flag, the default),
        // mirroring the Akismet fork. The reason (e.g. "keyword:casino") becomes
        // the submission's spamReason.
        $quarantineReason = null;
        $denylistHit = Plugin::getInstance()->getDenylistService()->match($form, $data);
        if ($denylistHit !== null) {
            if ($settings->denylistMode === Settings::DENYLIST_BLOCK) {
                return ['result' => ['submission' => null, 'errors' => null], 'data' => [], 'isSpam' => false];
            }
            $quarantineReason = $denylistHit;
        }

        // (5b) Per-form duplicate prevention (#140): the same payload/email/ip
        // hitting the form again inside the configured window. Like denylists, a
        // hit either drops silently (block) or flags as spam (flag). The first
        // matching reason wins, so a denylist hit takes precedence.
        if ($quarantineReason === null && $this->isDuplicate($form, $data)) {
            if ($settings->duplicateMode === Settings::DUPLICATE_BLOCK) {
                return ['result' => ['submission' => null, 'errors' => null], 'data' => [], 'isSpam' => false];
            }
            $quarantineReason = 'duplicate';
        }

        // (5c) Content spam scoring (Akismet). A spam verdict either drops the
        // submission silently (block — like the honeypot, no signal to the bot)
        // or saves it flagged as spam for review (flag, the default).
        $akismetSpam = Plugin::getInstance()->getAkismetService()->isSpam($form, $data);
        if ($akismetSpam && $settings->akismetMode === Settings::AKISMET_BLOCK) {
            return ['result' => ['submission' => null, 'errors' => null], 'data' => [], 'isSpam' => false];
        }

        // A submission is quarantined if any deterministic filter flagged it or
        // Akismet scored it spam; spamReason records the first/most specific cause.
        $isSpam = $quarantineReason !== null || $akismetSpam;
        $spamReason = $quarantineReason ?? ($akismetSpam ? 'akismet' : null);

        // The validated payload + spam verdict are returned for the caller
        // (submit() / update()) to persist as a create or an edit. Routing both
        // through this one core guarantees identical validation, conditional-logic
        // visibility, denylist/duplicate and Akismet behavior on every transport.
        return ['result' => null, 'data' => $data, 'isSpam' => $isSpam, 'spamReason' => $spamReason];
    }

    /**
     * Resolve the post-submit behavior for a completed submission: the success
     * message to show and the URL to redirect to (or null for an inline message).
     *
     * Single source of truth shared by every transport (the front-end controller
     * and the GraphQL mutation) so they always agree on the final message/redirect.
     *
     * - `message` falls back to the global {@see Settings::$submitMessage} when the
     *   per-form override is blank, with `{handle}`/`{submissionId}` placeholders
     *   interpolated from the submitted values.
     * - `redirectUrl` is null for the `message` action; for `url` the placeholders
     *   are interpolated and each substituted value is `rawurlencode()`d; for
     *   `entry` the form's `redirectEntryId` resolves to an entry on the
     *   submission's site and its URL is used (null when missing/disabled).
     *
     * @param array<string, mixed> $data the persisted submission data map (field_<id> => [..., 'value' => mixed])
     * @return array{message: string, redirectUrl: ?string}
     */
    public function resolvePostSubmit(Form $form, Submission $submission, array $data): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $placeholders = $this->buildPlaceholders($form, $submission, $data);

        $rawMessage = ($form->submitMessage !== null && trim($form->submitMessage) !== '')
            ? $form->submitMessage
            : $settings->submitMessage;

        // Conditional submit messages (#265): the first enabled rule whose
        // condition matches the submitted values overrides the default message.
        // Scoped to the `message` action only, and only when the edition may
        // evaluate conditional logic — a downgraded Solo keeps its stored rows but
        // skips them, falling straight to the default (no error, no data loss).
        if (
            $form->postSubmitAction === Form::POST_SUBMIT_MESSAGE
            && Editions::can(Editions::CAP_CONDITIONAL_LOGIC)
        ) {
            $conditionalMessage = $this->resolveConditionalMessage($form, $submission, $data);
            if ($conditionalMessage !== null) {
                $rawMessage = $conditionalMessage;
            }
        }

        $message = $this->interpolate($rawMessage, $placeholders, false);

        $redirectUrl = match ($form->postSubmitAction) {
            Form::POST_SUBMIT_URL => $form->redirectUrl !== null && trim($form->redirectUrl) !== ''
                ? $this->safeRedirectUrl(
                    $this->interpolate($form->redirectUrl, $placeholders, true),
                    (int) $submission->siteId,
                )
                : null,
            Form::POST_SUBMIT_ENTRY => $this->resolveEntryUrl($form, $submission),
            default => null,
        };

        return ['message' => $message, 'redirectUrl' => $redirectUrl];
    }

    /**
     * The raw (un-interpolated) message text of the first conditional submit
     * message ({@see SubmitMessagesService}) whose condition matches the submitted
     * values, resolved for the submission's site. Returns null when no rows are
     * configured, none match, or the first match has no translation for the
     * submitting site — so the caller keeps the form's default message.
     *
     * @param array<string, mixed> $data the persisted submission data map
     */
    private function resolveConditionalMessage(Form $form, Submission $submission, array $data): ?string
    {
        $rows = Plugin::getInstance()->getSubmitMessages()->getForFormAndSite((int) $form->id, (int) $submission->siteId);
        if ($rows === []) {
            return null;
        }

        $valuesByHandle = $this->valuesByHandle($form, $data);
        foreach ($rows as $row) {
            if (ConditionalEvaluator::isVisible(['conditional' => $row->conditional], $valuesByHandle)) {
                // First match wins. A null message means the submitting site has no
                // translation, so the caller falls back to the default.
                return $row->message;
            }
        }

        return null;
    }

    /**
     * Build a field-handle => submitted-value map from the persisted submission
     * data, so a conditional submit message rule (which references field handles)
     * can be evaluated. Values are kept raw (arrays intact) for
     * {@see ConditionalEvaluator}; a rule referencing a handle not present on the
     * form resolves to null and evaluates as non-matching (no throw).
     *
     * @param array<string, mixed> $data the persisted submission data map
     * @return array<string, mixed>
     */
    private function valuesByHandle(Form $form, array $data): array
    {
        $values = [];
        $formModel = new FormModel($form);
        foreach ($formModel->getFields() as $fieldId => $field) {
            $values[$field->getName()] = $data['field_' . $fieldId]['value'] ?? null;
        }

        return $values;
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

        // Capture the prior status so an approve transition (SPAM → non-spam) can
        // release the side effects that were withheld at submit time, exactly once.
        $wasSpam = $submission->readStatus === SubmissionStatus::SPAM;

        $submission->readStatus = $status;
        // Keep spamReason in step with the status: marking spam by hand records
        // 'manual' (unless a denylist/Akismet reason already set it); moving out
        // of spam (e.g. "Mark as not spam") clears it.
        if ($status === SubmissionStatus::SPAM) {
            $submission->spamReason ??= 'manual';
        } else {
            $submission->spamReason = null;
        }

        $saved = Craft::$app->getElements()->saveElement($submission);
        if (!$saved) {
            return false;
        }

        Plugin::getInstance()->getAudit()->log(AuditService::ACTION_SUBMISSION_STATUS, AuditService::TARGET_SUBMISSION, $submissionId, 'status → ' . $status);

        // Approving a quarantined false-positive completes its journey: fire the
        // integration dispatch + notification email that were suppressed while it
        // sat in spam. Guarded on the SPAM → non-spam edge so re-approving an
        // already-approved submission is a no-op (idempotent).
        if ($wasSpam && $status !== SubmissionStatus::SPAM) {
            $this->releaseWithheldSideEffects($submission);
        }

        return true;
    }

    /**
     * Fire the integration dispatch and notification email that were withheld
     * while a submission sat in the spam quarantine. Called once, on the approve
     * transition out of SPAM (see {@see self::updateStatus()}).
     */
    private function releaseWithheldSideEffects(Submission $submission): void
    {
        $form = $submission->getForm();
        if (!$form instanceof Form) {
            return;
        }

        $data = is_array($submission->data) ? $submission->data : [];

        // Integration dispatch (the after-save listener routes to IntegrationsService).
        $afterEvent = new SubmissionEvent($submission, $form, $data, true);
        Plugin::getInstance()->trigger(Plugin::EVENT_AFTER_SUBMISSION_SAVE, $afterEvent);

        // Notification + autoresponder emails (no-ops when neither is configured).
        // Sent inline: this is a CP approve action (not a visitor request), so the
        // withheld email — including any PDF/upload attachments (#143) — fires
        // immediately rather than being deferred to the queue.
        Plugin::getInstance()->getEmailService()->sendSubmissionEmail($form, $submission, $data);
    }

    /**
     * Whether this submission duplicates an earlier one on the same form within
     * the form's configured window (#140). The dedupe key is the form's
     * `duplicateKey`: the first email value (`email`), a hash of the data payload
     * (`content`), or the submitter's IP (`ip`). A window of 0 means "ever".
     * Returns false when prevention is off or the key cannot be resolved (e.g.
     * the `email` key but no email submitted).
     *
     * Candidate rows in the window are loaded and compared in PHP so the match is
     * exact (a JSON-blob LIKE would be brittle) and multi-site safe (`site('*')`).
     *
     * @param array<string, mixed> $data
     */
    private function isDuplicate(Form $form, array $data): bool
    {
        if (!$form->preventDuplicates || $form->id === null) {
            return false;
        }

        $fingerprint = $this->dedupeFingerprint($form, $data, $this->sourceIp());
        if ($fingerprint === null) {
            return false;
        }

        $query = Submission::find()
            ->site('*')
            ->formId((int) $form->id);

        if ($form->duplicateWindowMinutes > 0) {
            $threshold = Carbon::now()->subMinutes($form->duplicateWindowMinutes);
            $query->andWhere(['>=', 'elements.dateCreated', Db::prepareDateForDb($threshold)]);
        }

        foreach ($query->all() as $existing) {
            $existingData = is_array($existing->data) ? $existing->data : [];
            if ($this->dedupeFingerprint($form, $existingData, $existing->sourceIp) === $fingerprint) {
                return true;
            }
        }

        return false;
    }

    /**
     * The dedupe fingerprint for a submission under the form's key, or null when
     * it cannot be computed (so no false "duplicate" is reported).
     *
     * @param array<string, mixed> $data
     */
    private function dedupeFingerprint(Form $form, array $data, ?string $sourceIp): ?string
    {
        return match ($form->duplicateKey) {
            Form::DUPLICATE_KEY_CONTENT => 'content:' . $this->contentHash($data),
            Form::DUPLICATE_KEY_IP => ($sourceIp !== null && $sourceIp !== '') ? 'ip:' . $sourceIp : null,
            default => ($email = $this->firstEmail($data)) !== null ? 'email:' . strtolower($email) : null,
        };
    }

    /**
     * A stable hash of the submitted values, independent of key/field ordering or
     * the JSON round-trip, so two identical payloads fingerprint the same.
     *
     * @param array<string, mixed> $data
     */
    private function contentHash(array $data): string
    {
        $values = array_map(
            static fn($entry) => is_array($entry) ? ($entry['value'] ?? null) : $entry,
            $data,
        );
        ksort($values);

        return md5(json_encode($values) ?: '');
    }

    /**
     * The submitter's source IP, or null on the console / when unresolvable.
     */
    private function sourceIp(): ?string
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return null;
        }
        $ip = $request->getUserIP();
        return ($ip === null || $ip === '') ? null : $ip;
    }

    /**
     * The first email value in a submission's data payload, or null.
     *
     * @param array<string, mixed> $data
     */
    private function firstEmail(array $data): ?string
    {
        foreach ($data as $entry) {
            if (is_array($entry)
                && ($entry['type'] ?? '') === EmailFieldType::getType()
                && is_string($value = $entry['value'] ?? null) && $value !== '') {
                return $value;
            }
        }

        return null;
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
            // Postgres stores `data` as jsonb, which has no LIKE operator, so match
            // against its text form there; MySQL's json LIKEs as-is.
            $dataColumn = Craft::$app->getDb()->getIsPgsql()
                ? '[[simpleform_submissions.data]]::text'
                : '[[simpleform_submissions.data]]';

            return (int) $query
                ->andWhere(Db::parseParam('simpleform_submissions.userId', ':empty:'))
                ->andWhere(['like', $dataColumn, $email])
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
    private function resolveForm(FormModel|Form|string $form): ?Form
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
     * Normalize a field's value for storage. Composite field types
     * ({@see CompositeFieldType}) clamp the posted associative array to their
     * enabled sub-keys; every other field type stores its value untouched.
     *
     * @param FieldModel $field
     */
    private function serializeFieldValue(FieldModel $field, mixed $value): mixed
    {
        $fieldType = Plugin::getInstance()
            ->getFieldTypeRegistry()
            ->getFieldType($field->getType(), $field->getConfig());

        return $fieldType instanceof CompositeFieldType ? $fieldType->serializeValue($value) : $value;
    }

    /**
     * Build the placeholder map for post-submit interpolation: each field handle
     * maps to its submitted scalar value (arrays join with ", "), plus the
     * `submissionId` built-in.
     *
     * @param array<string, mixed> $data the persisted submission data map
     * @return array<string, string>
     */
    private function buildPlaceholders(Form $form, Submission $submission, array $data): array
    {
        $placeholders = [
            'submissionId' => $submission->id !== null ? (string) $submission->id : '',
            // Quiz scoring (#241): blank on non-quiz forms, so a success message
            // referencing {quizScore} simply renders empty there.
            'quizScore' => $submission->quizScore !== null ? (string) $submission->quizScore : '',
            'quizMaxScore' => $submission->quizMaxScore !== null ? (string) $submission->quizMaxScore : '',
            'quizPercentage' => $submission->quizPercentage !== null ? $submission->quizPercentage . '%' : '',
            'quizGrade' => (string) ($submission->quizGrade ?? ''),
        ];

        $formModel = new FormModel($form);
        foreach ($formModel->getFields() as $fieldId => $field) {
            $value = $data['field_' . $fieldId]['value'] ?? null;
            $placeholders[$field->getName()] = $this->stringifyValue($value);
        }

        return $placeholders;
    }

    /**
     * Reduce a submitted value to a single string for placeholder substitution.
     * Arrays (e.g. checkbox groups, file fields) join their scalar members with
     * ", "; null/bool/scalars stringify directly.
     */
    private function stringifyValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map('strval', array_filter($value, 'is_scalar')));
        }

        if (is_bool($value)) {
            return $value ? '1' : '';
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * Substitute `{token}` placeholders in a template string. Unknown tokens
     * resolve to an empty string. For URLs each substituted value is
     * `rawurlencode()`d so it is safe inside a query string/path; for messages the
     * raw value is used (the front-end sets it via `textContent`, so there is no
     * markup-injection risk).
     *
     * @param array<string, string> $placeholders
     */
    private function interpolate(string $template, array $placeholders, bool $encode): string
    {
        return (string) preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $m): string => $encode
                ? rawurlencode($placeholders[$m[1]] ?? '')
                : ($placeholders[$m[1]] ?? ''),
            $template,
        );
    }

    /**
     * Resolve the form's redirect entry to its URL on the submission's site.
     * Returns null when no entry is configured or the entry is missing/disabled
     * (no live URL) so the caller falls back to the inline message.
     */
    private function resolveEntryUrl(Form $form, Submission $submission): ?string
    {
        if ($form->redirectEntryId === null) {
            return null;
        }

        $entry = Entry::find()
            ->id($form->redirectEntryId)
            ->siteId((int) $submission->siteId)
            ->one();

        $url = $entry instanceof Entry ? $entry->getUrl() : null;

        return $url !== null ? $this->safeRedirectUrl($url, (int) $submission->siteId) : null;
    }

    /**
     * Return $url when it passes the post-submit redirect guard, else null.
     */
    private function safeRedirectUrl(string $url, int $siteId): ?string
    {
        $site = Craft::$app->getSites()->getSiteById($siteId);
        $host = $site !== null ? parse_url((string) $site->getBaseUrl(), PHP_URL_HOST) : null;

        return SafeUrl::isSafeRedirectUrl($url, is_string($host) ? $host : null) ? $url : null;
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

    /**
     * Resolve a field's persisted value through its field type's
     * {@see \anvildev\simpleform\fields\FieldType::normalizeStoredValue()}
     * hook (a passthrough for most types). Falls back to the raw value if the
     * type is unknown so an unregistered type never drops the submitted value.
     */
    private function normalizedValueForField(FieldModel $field, mixed $value): mixed
    {
        $fieldType = Plugin::getInstance()
            ->getFieldTypeRegistry()
            ->getFieldType($field->getType(), $field->getConfig());

        return $fieldType === null ? $value : $fieldType->normalizeStoredValue($value);
    }
}

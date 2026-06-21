<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\elements\Entry;
use craft\web\Request;
use craft\web\UploadedFile;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\events\SubmissionEvent;
use fabianhaef\simpleform\fields\CalculationFieldType;
use fabianhaef\simpleform\fields\CompositeFieldType;
use fabianhaef\simpleform\fields\FileFieldType;
use fabianhaef\simpleform\fields\HiddenFieldType;
use fabianhaef\simpleform\fields\RepeaterFieldType;
use fabianhaef\simpleform\fields\SignatureFieldType;
use fabianhaef\simpleform\helpers\RateLimiter;
use fabianhaef\simpleform\helpers\SignaturePng;
use fabianhaef\simpleform\models\FieldModel;
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
     * @return array{submission: Submission|null, errors: array<string, mixed>|null, data?: array<string, mixed>}
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
            $createdAssetIds = array_merge($createdAssetIds, $ids);
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
            $createdAssetIds = array_merge($createdAssetIds, $ids);
        }

        $userId = Craft::$app->getUser()->getId();

        $result = $this->submit($formElement, $values, [
            'honeypot' => (string) $request->getBodyParam('__honeypot', ''),
            'captchaToken' => null, // CaptchaService reads the request body itself.
            'userId' => $userId !== null ? (int) $userId : null,
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
     * @return array{submission: Submission|null, errors: array<string, mixed>|null, data?: array<string, mixed>}
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
            if (!$field->isInputType()) {
                continue;
            }
            $valuesByHandle[$field->getName()] = $this->valueForField($values, (int) $fieldId);
        }

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
            return ['submission' => null, 'errors' => $errors];
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

        // (5) Content spam scoring (Akismet). A spam verdict either drops the
        // submission silently (block — like the honeypot, no signal to the bot)
        // or saves it flagged as spam for review (flag, the default).
        $isSpam = Plugin::getInstance()->getAkismetService()->isSpam($form, $data);
        if ($isSpam && Plugin::getInstance()->getSettings()->akismetMode === Settings::AKISMET_BLOCK) {
            return ['submission' => null, 'errors' => null];
        }

        // (6) Build + save the submission element.
        $submission = new Submission();
        $submission->formId = (int) $form->id;
        $submission->siteId = (int) $siteId;
        $submission->data = $data;
        $submission->userId = isset($context['userId']) ? (int) $context['userId'] : null;
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

        // `data` is returned so post-submit resolution (the success message and a
        // templated redirect URL) can interpolate the submitted values without
        // re-reading the persisted row.
        return ['submission' => $submission, 'errors' => null, 'data' => $data];
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
        $message = $this->interpolate($rawMessage, $placeholders, false);

        $redirectUrl = match ($form->postSubmitAction) {
            'url' => $form->redirectUrl !== null && trim($form->redirectUrl) !== ''
                ? $this->interpolate($form->redirectUrl, $placeholders, true)
                : null,
            'entry' => $this->resolveEntryUrl($form, $submission),
            default => null,
        };

        return ['message' => $message, 'redirectUrl' => $redirectUrl];
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

        if ($fieldType instanceof CompositeFieldType) {
            return $fieldType->serializeValue($value);
        }

        return $value;
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
            $scalars = array_filter($value, static fn($v): bool => is_scalar($v));
            return implode(', ', array_map('strval', $scalars));
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
            static function(array $matches) use ($placeholders, $encode): string {
                $value = $placeholders[$matches[1]] ?? '';
                return $encode ? rawurlencode($value) : $value;
            },
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

        return $entry instanceof Entry ? $entry->getUrl() : null;
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
     * {@see \fabianhaef\simpleform\fields\FieldType::normalizeStoredValue()}
     * hook (a passthrough for most types). Falls back to the raw value if the
     * type is unknown so an unregistered type never drops the submitted value.
     */
    private function normalizedValueForField(FieldModel $field, mixed $value): mixed
    {
        $fieldType = Plugin::getInstance()
            ->getFieldTypeRegistry()
            ->getFieldType($field->getType(), $field->getConfig());

        if ($fieldType === null) {
            return $value;
        }

        return $fieldType->normalizeStoredValue($value);
    }
}

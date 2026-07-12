<?php

namespace anvildev\simpleform\controllers;

use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\helpers\DialCodes;
use anvildev\simpleform\helpers\FieldQueryHelper;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use anvildev\simpleform\helpers\SiteHelper;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\AuditService;
use anvildev\simpleform\services\FieldSyncService;
use anvildev\simpleform\services\FormPortabilityService;
use Craft;
use craft\db\Query;
use craft\enums\PropagationMethod;
use craft\helpers\DateTimeHelper;
use craft\models\Site;
use craft\web\Controller;
use yii\base\InvalidArgumentException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @phpstan-type VolumeOption array{handle: string, name: string}
 */
class FormsController extends Controller
{
    use SimpleFormControllerTrait;

    protected const PERMISSION = SimpleFormPermissions::MANAGE_FORMS;

    public function actionIndex(): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteForRequest($request);

        $forms = Form::find()
            ->siteId($site->id)
            ->orderBy(['elements_sites.title' => SORT_ASC])
            ->all();

        // Batch-load every listed form's fields in a bounded number of queries so
        // any per-form field access in the listing stays N+1-free.
        Form::eagerLoadFields($forms);

        $stencils = array_map(
            static fn($s): array => ['handle' => $s->handle, 'name' => $s->name, 'description' => $s->description],
            array_values(Plugin::getInstance()->getStencilLibrary()->getAll()),
        );

        return $this->renderTemplate('simple-form/forms/index', [
            'forms' => $forms,
            'currentSite' => $site,
            'stencils' => $stencils,
            'formStats' => $this->formStats($forms, (int) $site->id),
        ]);
    }

    /**
     * Per-form submission signal for the index listing: total count, spam count,
     * and the most recent submission timestamp. Two grouped aggregate queries
     * (not one per form) keep the listing N+1-free.
     *
     * @param Form[] $forms
     * @return array<int, array{count: int, spam: int, last: ?\DateTime}>
     */
    private function formStats(array $forms, int $siteId): array
    {
        $stats = [];
        $formIds = [];
        foreach ($forms as $form) {
            $id = (int) $form->id;
            $formIds[] = $id;
            $stats[$id] = ['count' => 0, 'spam' => 0, 'last' => null];
        }

        if ($formIds === []) {
            return $stats;
        }

        $totals = (new Query())
            ->select(['formId', 'c' => 'COUNT(*)', 'last' => 'MAX([[dateCreated]])'])
            ->from('{{%simpleform_submissions}}')
            ->where(['siteId' => $siteId, 'formId' => $formIds])
            ->groupBy(['formId'])
            ->all();
        foreach ($totals as $row) {
            $id = (int) $row['formId'];
            $stats[$id]['count'] = (int) $row['c'];
            $stats[$id]['last'] = $row['last'] !== null ? DateTimeHelper::toDateTime($row['last']) ?: null : null;
        }

        $spam = (new Query())
            ->select(['formId', 'c' => 'COUNT(*)'])
            ->from('{{%simpleform_submissions}}')
            ->where(['siteId' => $siteId, 'formId' => $formIds, 'readStatus' => SubmissionStatus::SPAM])
            ->groupBy(['formId'])
            ->all();
        foreach ($spam as $row) {
            $stats[(int) $row['formId']]['spam'] = (int) $row['c'];
        }

        return $stats;
    }

    public function actionEdit(?int $formId = null): Response
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteForRequest($request);

        if ($formId) {
            $form = Form::find()
                ->siteId($site->id)
                ->id($formId)
                ->status(null)
                ->one();

            // Not present on this site — fall back to wherever it exists and redirect there.
            if (!$form) {
                $form = $this->getFormOrFail((int)$formId);
                $existingSite = Craft::$app->getSites()->getSiteById($form->siteId);
                if ($existingSite) {
                    return $this->redirect("simple-form/forms/edit/{$formId}?site={$existingSite->handle}");
                }
            }
        } else {
            $form = new Form();
            $form->siteId = $site->id;
        }

        // Fetch fields with this site's translatable label/helpText, in builder shape.
        $fields = $form->id ? FieldQueryHelper::fieldsForForm((int)$form->id, $site->id) : [];

        return $this->renderEdit($form, $site, $this->fieldsToBuilderJson($fields));
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $site = SiteHelper::getSiteFromPost($request);
        $formId = $request->getBodyParam('formId');

        if ($formId) {
            // Load (or create) the element on the posted site so its per-site content saves there.
            $form = Form::find()->siteId($site->id)->id($formId)->status(null)->one()
                ?? Form::find()->siteId('*')->id($formId)->status(null)->one();
            if (!$form) {
                throw new NotFoundHttpException('Form not found');
            }
            $form->siteId = $site->id;
        } else {
            $form = new Form();
            $form->siteId = $site->id;
        }

        // Snapshot the saved state before posted params overwrite it, so the
        // edition gate can tell newly-enabled Pro capabilities from ones the form
        // already had (no-new-escalation rule).
        $existingFields = $form->id ? FieldQueryHelper::fieldsForForm((int)$form->id, $site->id) : [];
        $priorSaveResume = (bool) $form->allowSaveResume;
        // Scalar Pro modes aren't derivable from the field set, so snapshot them
        // too (a new form's defaults are all "off"), keyed by capability handle.
        $priorModes = [
            Editions::CAP_CONVERSATIONAL => $form->renderMode === 'conversational',
            Editions::CAP_QUIZ => $form->quizMode,
            Editions::CAP_PARTIAL_CAPTURE => $form->capturePartials,
        ];

        $form->name = $request->getBodyParam('name');
        $form->handle = $request->getBodyParam('handle');
        $form->title = $request->getBodyParam('title');
        $form->description = $request->getBodyParam('description');
        // Email config has moved to the form's Notifications screen; the built-in
        // emailTo/emailSubject/emailReplyTo/emailBody columns are no longer edited
        // here. They are intentionally NOT read from the post, so an existing
        // form's stored values are preserved on save; the legacy send path remains
        // a dormant fallback for forms that have no notification rows.
        $form->allowSaveResume = (bool) $request->getBodyParam('allowSaveResume');

        // Post-submit behavior (#133). The message/URL/error overrides are
        // per-site translatable content; the action + entry id are shared.
        $postSubmitAction = (string) $request->getBodyParam('postSubmitAction', Form::POST_SUBMIT_MESSAGE);
        $form->postSubmitAction = in_array($postSubmitAction, Form::POST_SUBMIT_ACTIONS, true) ? $postSubmitAction : Form::POST_SUBMIT_MESSAGE;
        $redirectEntryId = $request->getBodyParam('redirectEntryId');
        if (is_array($redirectEntryId)) {
            $redirectEntryId = reset($redirectEntryId) ?: null;
        }
        $form->redirectEntryId = $redirectEntryId !== null && $redirectEntryId !== ''
            ? (int) $redirectEntryId
            : null;
        $form->submitMessage = $request->getBodyParam('submitMessage');
        $form->errorMessage = $request->getBodyParam('errorMessage');
        $form->redirectUrl = $request->getBodyParam('redirectUrl');

        // Scheduling window + quota. Empty inputs clear the bound (open-ended /
        // unlimited). forms.dateTimeField posts a {date, time, timezone} array,
        // which DateTimeHelper::toDateTime normalises (returns false when blank).
        $openDate = DateTimeHelper::toDateTime($request->getBodyParam('openDate'));
        $closeDate = DateTimeHelper::toDateTime($request->getBodyParam('closeDate'));
        $form->openDate = $openDate instanceof \DateTime ? $openDate : null;
        $form->closeDate = $closeDate instanceof \DateTime ? $closeDate : null;
        $submissionLimit = $request->getBodyParam('submissionLimit');
        $form->submissionLimit = is_numeric($submissionLimit) && (int) $submissionLimit > 0
            ? (int) $submissionLimit
            : null;
        $form->closedMessage = $request->getBodyParam('closedMessage') ?: null;

        // Login + per-user limit (#135). Shared flags + per-site notice overrides.
        $form->requireLogin = (bool) $request->getBodyParam('requireLogin');
        $form->loginRequiredMessage = $request->getBodyParam('loginRequiredMessage');
        $submissionsPerUser = trim((string) $request->getBodyParam('submissionsPerUser', ''));
        $form->submissionsPerUser = $submissionsPerUser !== '' ? (int) $submissionsPerUser : null;
        $form->guestLimitKey = (string) $request->getBodyParam('guestLimitKey', Form::GUEST_LIMIT_NONE);
        $form->userLimitMessage = $request->getBodyParam('userLimitMessage');

        // Custom render template (#137). Opt-in lightswitch + optional per-form
        // path override; blank path falls back to the global default in settings.
        // Shared, structural.
        $form->useCustomTemplate = (bool) $request->getBodyParam('useCustomTemplate');
        $templatePath = trim((string) $request->getBodyParam('templatePath', ''));
        $form->templatePath = $templatePath !== '' ? $templatePath : null;

        // Duplicate prevention (#140). Shared flags + window.
        $form->preventDuplicates = (bool) $request->getBodyParam('preventDuplicates');
        $form->duplicateWindowMinutes = (int) $request->getBodyParam('duplicateWindowMinutes', 0);
        $form->duplicateKey = (string) $request->getBodyParam('duplicateKey', Form::DUPLICATE_KEY_EMAIL);

        // Front-end editing (#144). Shared flags + edit window.
        $form->allowEditing = (bool) $request->getBodyParam('allowEditing');
        $form->editWindowMinutes = max(0, (int) $request->getBodyParam('editWindowMinutes', 0));

        // Quiz scoring (#241). The answer key (correct/points) lives in each
        // choice field's config; only the mode flag + grade bands are form-level.
        $form->quizMode = (bool) $request->getBodyParam('quizMode');
        $form->quizGradeBands = trim((string) $request->getBodyParam('quizGradeBands', '')) ?: null;

        // UTM/referrer auto-capture (#249).
        $form->autoCaptureAttribution = (bool) $request->getBodyParam('autoCaptureAttribution');

        // Passive partial capture (#242).
        $form->capturePartials = (bool) $request->getBodyParam('capturePartials');

        // Conversational render mode (#239).
        $renderMode = (string) $request->getBodyParam('renderMode', 'standard');
        $form->renderMode = in_array($renderMode, ['standard', 'conversational'], true) ? $renderMode : 'standard';

        // Query-string prefill default (#316).
        $form->prefillFromQuery = (bool) $request->getBodyParam('prefillFromQuery');

        $form->propagationMethod = PropagationMethod::tryFrom(
            (string)$request->getBodyParam('propagationMethod', 'none')
        ) ?? PropagationMethod::None;

        // The field builder posts its whole set as JSON; sync it after the form saves.
        $items = $this->parseFieldsData((string)$request->getBodyParam('fieldsData', ''));
        $fieldSync = new FieldSyncService();

        // Conditional submit messages (#266): the Confirmation tab posts its whole
        // ordered set as JSON, synced (validate → replace-in-transaction) after the
        // form saves, mirroring the field builder. Kept for the failure re-renders
        // so in-progress rows aren't lost.
        $messageRows = $this->parseFieldsData((string)$request->getBodyParam('submitMessagesData', ''));
        $submitMessagesJson = $this->encodeBuilderJson($messageRows);
        // Handles the message rules may reference — the form's live field handles.
        $validHandles = [];
        foreach ($items as $item) {
            $handle = trim((string)($item['handle'] ?? ''));
            if ($handle !== '') {
                $validHandles[$handle] = true;
            }
        }
        $submitMessages = Plugin::getInstance()->getSubmitMessages();

        // Validate the field set before any DB writes so a bad field never half-saves.
        $fieldErrors = $fieldSync->validate($items, $form->renderMode === 'conversational');
        if ($fieldErrors) {
            // Flash the whole set (#289): one-error-per-save round-trips are
            // brutal when a paste-in form has five problems at once.
            Craft::$app->getSession()->setError(implode(' ', $fieldErrors));
            return $this->renderEdit($form, $site, $this->encodeBuilderJson($items), $submitMessagesJson);
        }

        // Validate the conditional-message set the same way (whole set up front).
        $messageErrors = $submitMessages->validate($messageRows, $validHandles);
        if ($messageErrors) {
            Craft::$app->getSession()->setError(implode(' ', $messageErrors));
            return $this->renderEdit($form, $site, $this->encodeBuilderJson($items), $submitMessagesJson);
        }

        // Edition gate (authoritative): Solo may not introduce *new* Pro field
        // types. Pro fields already on the form survive a downgrade and stay
        // editable; the builder palette only hides these client-side.
        $submittedTypes = array_map(
            static fn(array $item): string => (string)($item['type'] ?? ''),
            array_filter($items, 'is_array'),
        );
        $existingTypes = array_map(
            static fn(array $row): string => (string)$row['type'],
            $existingFields,
        );
        $blockedProFields = Editions::blockedNewProFields(
            array_values($submittedTypes),
            array_values($existingTypes),
        );
        if ($blockedProFields) {
            Craft::$app->getSession()->setError(Craft::t(
                'simple-form',
                'These field types require the Pro edition: {types}',
                ['types' => implode(', ', $blockedProFields)],
            ));
            return $this->renderEdit($form, $site, $this->encodeBuilderJson($items), $submitMessagesJson);
        }

        // Edition gate (authoritative): Solo may not newly enable Pro form-level
        // capabilities. Field-set-derived ones (conditional logic, multi-page,
        // logic jumps, save & continue) and scalar form modes (conversational
        // render, quiz scoring, partial capture) are both diffed against the saved
        // form, so a feature already on it survives a downgrade and stays editable;
        // only *introducing* one on Solo is blocked (no-new-escalation).
        $blockedCaps = array_merge(
            Editions::blockedNewFormCapabilities(
                $items,
                $form->allowSaveResume,
                $existingFields,
                $priorSaveResume,
            ),
            Editions::blockedNewFormModes(
                [
                    Editions::CAP_CONVERSATIONAL => $form->renderMode === 'conversational',
                    Editions::CAP_QUIZ => $form->quizMode,
                    Editions::CAP_PARTIAL_CAPTURE => $form->capturePartials,
                ],
                $priorModes,
            ),
        );
        if ($blockedCaps) {
            Craft::$app->getSession()->setError(Craft::t(
                'simple-form',
                'These features require the Pro edition: {features}',
                ['features' => implode(', ', array_map($this->capabilityLabel(...), $blockedCaps))],
            ));
            return $this->renderEdit($form, $site, $this->encodeBuilderJson($items), $submitMessagesJson);
        }

        // Edition gate (authoritative): Solo may not create *new* conditional
        // submit messages. A posted row whose id isn't already stored on the form
        // is a new row; existing rows survive a downgrade and stay editable /
        // deletable / reorderable (no-new-escalation rule).
        if (!Editions::isPro()) {
            $existingMessageIds = $form->id ? $submitMessages->idsForForm((int) $form->id) : [];
            foreach ($messageRows as $messageRow) {
                // A half-added empty row is dropped on save, so it isn't a new row.
                if (trim((string) ($messageRow['message'] ?? '')) === '' && empty($messageRow['conditional']['rules'])) {
                    continue;
                }
                $rawId = $messageRow['id'] ?? null;
                $id = is_numeric($rawId) ? (int) $rawId : null;
                if ($id === null || !in_array($id, $existingMessageIds, true)) {
                    Craft::$app->getSession()->setError(Craft::t(
                        'simple-form',
                        'Conditional submit messages require the Pro edition.',
                    ));
                    return $this->renderEdit($form, $site, $this->encodeBuilderJson($items), $submitMessagesJson);
                }
            }
        }

        // Editing an HTML layout block's body requires a dedicated permission.
        // Re-checked here (after parsing the posted set) so a forged HTML block
        // cannot slip past the controller's MANAGE_FORMS gate. Reorder/delete of
        // an existing block — and an unchanged body — are not gated.
        $identity = Craft::$app->getUser()->getIdentity();
        if (
            $identity !== null
            && !$identity->admin
            && !$identity->can(SimpleFormPermissions::EDIT_HTML_BLOCKS)
            && $fieldSync->htmlBlockBodyChanged($items, $site->id)
        ) {
            Craft::$app->getSession()->setError(
                Craft::t('simple-form', 'You don’t have permission to edit HTML layout blocks.')
            );
            return $this->renderEdit($form, $site, $this->encodeBuilderJson($items), $submitMessagesJson);
        }

        if (!Craft::$app->getElements()->saveElement($form)) {
            // The inline errors render on the Details pane, which re-opens
            // selected + error-badged (#289) — the flash still names them so
            // the author isn't left staring at a seemingly clean Build tab.
            $firstErrors = $form->getFirstErrors();
            $summary = $firstErrors !== []
                ? Craft::t('simple-form', 'Unable to save form:') . ' ' . implode(' ', $firstErrors)
                : Craft::t('simple-form', 'Unable to save form');
            Craft::$app->getSession()->setError($summary);
            Craft::warning('Form save failed: ' . json_encode($form->getErrors()), 'simple-form');
            return $this->renderEdit($form, $site, $this->encodeBuilderJson($items), $submitMessagesJson);
        }

        try {
            $fieldSync->sync($form, $items, $site->id);
            // Sync the conditional messages against the freshly-saved field set, so
            // rules are pruned against the handles that actually survived the save.
            $submitMessages->sync((int) $form->id, $messageRows, $site->id, $validHandles, $form->supportedSiteIds());
        } catch (\Throwable $e) {
            Craft::warning('Field sync failed: ' . $e->getMessage(), 'simple-form');
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Form saved, but its fields could not be saved.'));
            return $this->redirect("simple-form/forms/edit/{$form->id}?site={$site->handle}");
        }

        Plugin::getInstance()->getAudit()->log(AuditService::ACTION_FORM_SAVE, AuditService::TARGET_FORM, (int) $form->id, (string) ($form->title ?? $form->name));

        // Save-time guard rail (#267): a conditional message rule referencing a
        // field handle that no longer exists on the form is pruned above and
        // evaluates as non-matching at runtime, so it can silently stop matching
        // after an unrelated field delete/rename. Surface a non-blocking warning
        // (appended to the save notice) so the owner can fix or remove the rule.
        $notice = Craft::t('simple-form', 'Form saved successfully');
        $danglingHandles = $submitMessages->danglingReferences($messageRows, $validHandles);
        if ($danglingHandles !== []) {
            $notice .= ' ' . Craft::t(
                'simple-form',
                'A conditional message rule references a field that no longer exists: {handles}. That condition was ignored.',
                ['handles' => implode(', ', $danglingHandles)],
            );
        }

        // Same guard rail for field visibility/required rules and logic jumps
        // (#288): sync() prunes rules whose target left the set, so tell the
        // owner which fields just lost rules instead of dropping them silently.
        $prunedRules = FieldSyncService::prunedRuleReferences($items);
        if ($prunedRules !== []) {
            $notice .= ' ' . Craft::t(
                'simple-form',
                'Rules on {fields} referenced fields that no longer exist ({targets}) and were removed.',
                [
                    'fields' => implode(', ', array_keys($prunedRules)),
                    'targets' => implode(', ', array_unique(array_merge(...array_values($prunedRules)))),
                ],
            );
        }

        Craft::$app->getSession()->setNotice($notice);
        return $this->redirect("simple-form/forms/edit/{$form->id}?site={$site->handle}");
    }

    /**
     * Deep-copy an existing form (the edit screen's "Save as a new form" button)
     * and redirect to the copy's edit screen.
     *
     * @throws NotFoundHttpException if the source form does not exist
     * @throws \Throwable if the copy cannot be saved
     */
    public function actionDuplicate(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('formId');

        // Duplication is element-wide (all sites), so load from any site.
        $source = Form::find()->siteId('*')->id($formId)->status(null)->one();
        if (!$source) {
            throw new NotFoundHttpException('Form not found');
        }

        try {
            $copy = Plugin::getInstance()->getFormClone()->duplicate($source);
        } catch (InvalidArgumentException $e) {
            // Edition gate (or invalid source): the message is author-facing.
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect("simple-form/forms/edit/{$formId}");
        } catch (\Throwable $e) {
            Craft::warning('Form duplicate failed: ' . $e->getMessage(), 'simple-form');
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t duplicate the form.'));
            return $this->redirect("simple-form/forms/edit/{$formId}");
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Form duplicated.'));
        return $this->redirect("simple-form/forms/edit/{$copy->id}");
    }

    /**
     * Create a new form pre-populated from a built-in stencil and redirect to its
     * edit screen.
     *
     * @throws NotFoundHttpException if the stencil handle is unknown
     * @throws \Throwable if the form cannot be saved
     */
    public function actionNewFromStencil(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $handle = (string) $request->getRequiredBodyParam('stencil');

        $stencil = Plugin::getInstance()->getStencilLibrary()->getByHandle($handle);
        if ($stencil === null) {
            throw new NotFoundHttpException('Stencil not found');
        }

        try {
            $form = Plugin::getInstance()->getFormClone()->createFromStencil($stencil);
        } catch (InvalidArgumentException $e) {
            // Edition gate: the message is author-facing.
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect('simple-form/forms');
        } catch (\Throwable $e) {
            Craft::warning('Stencil create failed: ' . $e->getMessage(), 'simple-form');
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t create the form from the stencil.'));
            return $this->redirect('simple-form/forms');
        }

        Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Form created.'));
        return $this->redirect("simple-form/forms/edit/{$form->id}");
    }

    /**
     * Stream a form's portable, secret-free definition as a JSON download (#139).
     */
    public function actionExport(int $formId): Response
    {
        $form = Form::find()->siteId('*')->id($formId)->status(null)->one();
        if (!$form) {
            throw new NotFoundHttpException('Form not found');
        }

        $json = Plugin::getInstance()->getPortability()->exportJson($form);
        $filename = ($form->handle ?: 'form') . '.json';

        Plugin::getInstance()->getAudit()->log(AuditService::ACTION_FORM_EXPORT, AuditService::TARGET_FORM, (int) $form->id, (string) ($form->title ?? $form->name));

        /** @var \craft\web\Response $response */
        $response = Craft::$app->getResponse();
        return $response->sendContentAsFile(
            $json,
            $filename,
            ['mimeType' => 'application/json'],
        );
    }

    /**
     * Import a form definition from an uploaded JSON file (#139), then redirect to
     * the new form's edit screen with any warnings surfaced as a notice.
     */
    public function actionImport(): Response
    {
        $this->requirePostRequest();

        $upload = \yii\web\UploadedFile::getInstanceByName('file');
        if ($upload === null || $upload->tempName === '') {
            Craft::$app->getSession()->setError(Craft::t('simple-form', 'Please choose a JSON file to import.'));
            return $this->redirect('simple-form/forms');
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $mode = (string) $request->getBodyParam('mode', FormPortabilityService::MODE_RENAME);
        $json = (string) file_get_contents($upload->tempName);

        try {
            $result = Plugin::getInstance()->getPortability()->importJson($json, ['mode' => $mode]);
        } catch (\Throwable $e) {
            Craft::warning('Form import failed: ' . $e->getMessage(), 'simple-form');
            Craft::$app->getSession()->setError($e->getMessage());
            return $this->redirect('simple-form/forms');
        }

        $form = $result->form;
        $notice = Craft::t('simple-form', 'Form imported.');
        if ($result->warnings !== []) {
            $notice .= ' ' . implode(' ', $result->warnings);
        }
        Craft::$app->getSession()->setNotice($notice);

        return $this->redirect("simple-form/forms/edit/{$form?->id}");
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $formId = $request->getRequiredBodyParam('formId');

        // Deletion is element-wide (all sites), so load from any site.
        $form = $this->getFormOrFail((int)$formId);

        if (!Craft::$app->getElements()->deleteElement($form)) {
            if (!$request->getAcceptsJson()) {
                Craft::$app->getSession()->setError(Craft::t('simple-form', 'Couldn’t delete the form.'));
                return $this->redirect('simple-form/forms');
            }
            return $this->asJsonErrors($form->getErrors());
        }

        Plugin::getInstance()->getAudit()->log(AuditService::ACTION_FORM_DELETE, AuditService::TARGET_FORM, (int) $formId, (string) ($form->title ?? $form->name));

        // The forms index posts here as a full-page formsubmit, not Ajax.
        if (!$request->getAcceptsJson()) {
            Craft::$app->getSession()->setNotice(Craft::t('simple-form', 'Form deleted.'));
            return $this->redirect('simple-form/forms');
        }

        return $this->asJsonSuccess();
    }

    /**
     * Render the form edit screen with the field-builder seeded from the given
     * builder JSON (DB fields on load, or the posted set when re-rendering after
     * a validation failure so in-progress field edits aren't lost).
     */
    private function renderEdit(Form $form, Site $site, string $builderDataJson, ?string $submitMessagesJson = null): Response
    {
        $supportedSites = $this->getSupportedSitesForForm($form);
        // Conditional submit messages (#266) seeded from the DB for this site, or
        // the posted set on a validation-failure re-render so typed rows survive.
        $submitMessagesJson ??= $this->submitMessagesToJson((int) ($form->id ?? 0), $site->id);

        // Resolve the redirect entry (on the edited site) for the element select.
        $redirectEntry = null;
        if ($form->redirectEntryId !== null) {
            $redirectEntry = \craft\elements\Entry::find()
                ->id($form->redirectEntryId)
                ->siteId($site->id)
                ->status(null)
                ->one();
        }

        /** @var list<VolumeOption> $volumes */
        $volumes = array_values(array_map(
            static fn($v): array => ['handle' => (string) $v->handle, 'name' => (string) $v->name],
            Craft::$app->getVolumes()->getAllVolumes(),
        ));

        // Curated dial-code list for the Phone field's inspector controls,
        // localized per site. Structural config (not content), so it ships to
        // the builder as a flat list rather than through the translation path.
        $phoneCountries = [];
        foreach (DialCodes::all() as $iso => $meta) {
            $phoneCountries[] = ['iso' => $iso, 'dial' => $meta['dial'], 'label' => DialCodes::label($iso)];
        }

        $identity = Craft::$app->getUser()->getIdentity();
        $canEditHtmlBlocks = $identity !== null
            && ($identity->admin || $identity->can(SimpleFormPermissions::EDIT_HTML_BLOCKS));

        return $this->renderTemplate('simple-form/forms/edit', [
            'form' => $form,
            'currentSite' => $site,
            'supportedSites' => $supportedSites,
            'builderData' => $builderDataJson,
            'submitMessagesData' => $submitMessagesJson,
            // At-a-glance submission stats for the saved form's Stats tab (#cp).
            'stats' => $this->formEditStats($form, $site),
            'redirectEntry' => $redirectEntry,
            'volumes' => $volumes,
            'phoneCountries' => $phoneCountries,
            'canEditHtmlBlocks' => $canEditHtmlBlocks,
            // Selectable sources per element-relation type (section/group/volume
            // handles), so the field builder can offer a source picker scoped to
            // the chosen element type.
            'relationSources' => $this->relationSources($volumes),
            // The source site authors canonical option labels; other sites only
            // translate them. Single-site forms are always their own source.
            'isSourceSite' => count($supportedSites) <= 1
                || $site->id === Craft::$app->getSites()->getPrimarySite()->id,
            // Pro features this form already uses while running on Solo (a
            // downgrade): the editor shows a non-blocking banner so the author
            // knows what won't take effect and can't add more.
            'proFeaturesInUse' => $this->proFeaturesInUse($form, $site),
        ]);
    }

    /**
     * At-a-glance submission stats for the form edit screen's Stats tab: the
     * status breakdown (total/new/read/archived/spam) plus the most recent
     * submission's timestamp. Null for an unsaved form. Counts go through the
     * element query (same source as the Submissions index and Dashboard).
     *
     * @return array{breakdown: array{total: int, new: int, read: int, archived: int, spam: int}, last: ?\DateTime}|null
     */
    private function formEditStats(Form $form, Site $site): ?array
    {
        if (!$form->id) {
            return null;
        }

        $last = Submission::find()
            ->siteId($site->id)
            ->formId((int) $form->id)
            ->status(null)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->one();

        return [
            'breakdown' => Plugin::getInstance()->getReports()->statusBreakdown($site->id, (int) $form->id),
            'last' => $last?->dateCreated,
        ];
    }

    /**
     * The human-facing Pro features an existing form already uses while the
     * active edition is Solo. Empty on Pro, or for a form with no Pro usage.
     *
     * @return list<string>
     */
    private function proFeaturesInUse(Form $form, Site $site): array
    {
        if (Editions::isPro() || !$form->id) {
            return [];
        }

        $fields = FieldQueryHelper::fieldsForForm((int)$form->id, $site->id);
        $features = [];

        $proFields = Editions::blockedNewProFields(
            array_map(static fn(array $row): string => (string)$row['type'], $fields),
            [],
        );
        if ($proFields !== []) {
            $features[] = Craft::t('simple-form', 'Pro field types ({types})', ['types' => implode(', ', $proFields)]);
        }

        // Derive the form-level capabilities from the same enumeration the save
        // gate uses (empty "existing" => every Pro capability currently in use is
        // reported), so the banner and the gate can never drift apart. The scalar
        // modes are diffed the same way (empty "stored" => report whatever is on).
        $capabilities = array_merge(
            Editions::blockedNewFormCapabilities($fields, (bool)$form->allowSaveResume, [], false),
            Editions::blockedNewFormModes(
                [
                    Editions::CAP_CONVERSATIONAL => $form->renderMode === 'conversational',
                    Editions::CAP_QUIZ => $form->quizMode,
                    Editions::CAP_PARTIAL_CAPTURE => $form->capturePartials,
                ],
                [],
            ),
        );
        foreach ($capabilities as $capability) {
            $features[] = $this->capabilityLabel($capability);
        }

        return $features;
    }

    /**
     * The selectable sources for each element-relation field type — section,
     * category-group, tag-group, user-group, and volume handles + names — so the
     * field builder can render a source picker scoped to the chosen element type.
     * Volumes are reused from the file-field volume list already gathered.
     *
     * @param list<VolumeOption> $volumes
     * @return array<string, list<VolumeOption>>
     */
    private function relationSources(array $volumes): array
    {
        /** @var \craft\web\Application $app */
        $app = Craft::$app;

        $map = static fn(array $items): array => array_values(array_map(
            static fn($i): array => ['handle' => (string) $i->handle, 'name' => (string) $i->name],
            $items,
        ));

        return [
            'entry' => $map($app->getEntries()->getAllSections()),
            'category' => $map($app->getCategories()->getAllGroups()),
            'tag' => $map($app->getTags()->getAllTagGroups()),
            'user' => $map($app->getUserGroups()->getAllGroups()),
            'asset' => array_values($volumes),
        ];
    }

    /**
     * Human-facing label for a gated form capability, for the Pro-required error.
     */
    private function capabilityLabel(string $capability): string
    {
        return match ($capability) {
            Editions::CAP_CONDITIONAL_LOGIC => Craft::t('simple-form', 'conditional logic'),
            Editions::CAP_MULTI_PAGE => Craft::t('simple-form', 'multi-page forms'),
            Editions::CAP_SAVE_CONTINUE => Craft::t('simple-form', 'save & continue later'),
            Editions::CAP_CONVERSATIONAL => Craft::t('simple-form', 'conversational render mode'),
            Editions::CAP_QUIZ => Craft::t('simple-form', 'quiz scoring'),
            Editions::CAP_PARTIAL_CAPTURE => Craft::t('simple-form', 'partial capture'),
            default => $capability,
        };
    }

    /**
     * Decode the field builder's posted JSON into an ordered array of field items.
     *
     * @return array<int,array<string,mixed>>
     */
    private function parseFieldsData(string $raw): array
    {
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * Map DB field rows to the builder's field shape and encode as JSON.
     *
     * @param array<int,array<string,mixed>> $fields
     */
    private function fieldsToBuilderJson(array $fields): string
    {
        return $this->encodeBuilderJson(array_map(static fn(array $f): array => [
            'id' => (int)$f['id'],
            'type' => (string)$f['type'],
            'handle' => (string)$f['name'],
            'label' => (string)($f['label'] ?? $f['name']),
            'required' => (bool)$f['required'],
            'helpText' => (string)($f['helpText'] ?? ''),
            'errorMessage' => (string)($f['errorMessage'] ?? ''),
            'config' => self::configWithSiteLabels(
                is_array($f['config'] ?? null) ? $f['config'] : [],
                is_array($f['optionLabels'] ?? null) ? $f['optionLabels'] : []
            ),
        ], $fields));
    }

    /**
     * Annotate each choice option with this site's translated label (`siteLabel`),
     * so the builder can show the per-site translation column alongside the
     * source label. The siteLabel rides with its option, keeping translations
     * aligned to the right value across add/remove/reorder.
     *
     * @param array<string,mixed> $config
     * @param array<string,string> $optionLabels value => localized label
     * @return array<string,mixed>
     */
    private static function configWithSiteLabels(array $config, array $optionLabels): array
    {
        if (!isset($config['options']) || !is_array($config['options'])) {
            return $config;
        }

        foreach ($config['options'] as &$opt) {
            if (!is_array($opt) || !isset($opt['value'])) {
                continue;
            }
            $opt['siteLabel'] = (string)($optionLabels[(string)$opt['value']] ?? '');
        }
        unset($opt);

        return $config;
    }

    /**
     * The form's conditional submit messages (#266) in the builder shape the
     * Confirmation tab's JS reads — each row with its structural condition and the
     * message text resolved to the editing site (empty when untranslated there).
     * Empty for an unsaved form.
     */
    private function submitMessagesToJson(int $formId, int $siteId): string
    {
        if ($formId === 0) {
            return '[]';
        }

        $rows = Plugin::getInstance()->getSubmitMessages()->getForFormAndSite($formId, $siteId);

        return $this->encodeBuilderJson(array_map(static fn($row): array => [
            'id' => (int) $row->id,
            'conditional' => $row->conditional,
            'message' => (string) ($row->message ?? ''),
        ], $rows));
    }

    /**
     * JSON-encode builder items for safe inline embedding in a <script> block.
     *
     * @param array<int,mixed> $items
     */
    private function encodeBuilderJson(array $items): string
    {
        return json_encode(
            array_values($items),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
        ) ?: '[]';
    }

    /**
     * The sites this form is (or would be) saved to AND the user may edit, for
     * the native CP header site selector.
     *
     * @return \craft\models\Site[]
     */
    private function getSupportedSitesForForm(Form $form): array
    {
        $sitesService = Craft::$app->getSites();
        $editableSiteIds = $sitesService->getEditableSiteIds();
        $sites = [];
        foreach ($form->getSupportedSites() as $id) {
            $siteId = is_array($id) ? $id['siteId'] : $id;
            if (in_array($siteId, $editableSiteIds, true) && ($site = $sitesService->getSiteById($siteId))) {
                $sites[] = $site;
            }
        }
        return $sites;
    }
}

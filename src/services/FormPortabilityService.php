<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\elements\Entry;
use craft\enums\PropagationMethod;
use craft\helpers\DateTimeHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\helpers\FormContentHelper;
use fabianhaef\simpleform\models\ImportResult;
use fabianhaef\simpleform\models\IntegrationModel;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Export a form's full definition to a portable, versioned, secret-free JSON
 * document and import it back on any install to recreate the form (#139).
 *
 * The export carries no submissions and no integration credentials: integration
 * secrets are redacted to {@see IntegrationsService::REDACTED} on the way out, and
 * on import an unmatched integration is recreated as a *disabled* placeholder so it
 * is never silently enabled with empty credentials. Per-site content is keyed by
 * site handle (ids are not portable); import re-resolves handles to local site ids
 * and skips—with a warning—sites that do not exist on the target. Field handles
 * travel verbatim so conditional rules (which reference fields by handle) re-bind
 * correctly. Import runs in one transaction; a mid-import failure leaves no form.
 *
 * This is deliberately *not* Craft project config: forms are content-like elements,
 * so import/export is an explicit file you move on demand, unaffected by
 * `allowAdminChanges` and never written to `config/project/`.
 *
 * @author Fabian Haefliger
 * @since 2.11.0
 */
class FormPortabilityService extends Component
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    /**
     * Current export schema version. Bump when the document shape changes.
     * v2 (#226) adds the full form-level settings block; it is additive, so a v1
     * document still imports (the missing keys keep the element defaults).
     */
    public const SCHEMA_VERSION = 2;

    /** Conflict mode: derive a unique handle when the target handle exists. */
    public const MODE_RENAME = 'rename';

    /** Conflict mode: delete the existing form with that handle, then recreate. */
    public const MODE_REPLACE = 'replace';

    /** Conflict mode: abort with an error if the target handle exists. */
    public const MODE_ABORT = 'abort';

    /**
     * @var list<string>
     */
    public const MODES = [self::MODE_RENAME, self::MODE_REPLACE, self::MODE_ABORT];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Build the portable, secret-free export document for a form.
     *
     * @return array<string, mixed>
     */
    public function export(Form $form): array
    {
        $formId = (int)$form->id;
        $sites = Craft::$app->getSites();
        $supportedSiteIds = $form->supportedSiteIds() ?: [(int)$form->siteId];

        return [
            '_meta' => [
                'schemaVersion' => self::SCHEMA_VERSION,
                'plugin' => 'fabianhaef/craft-simple-form',
                'pluginVersion' => Plugin::getInstance()->getVersion(),
                'exportedAt' => (new \DateTime('now', new \DateTimeZone('UTC')))->format(\DateTime::ATOM),
                'exportedFromSite' => $sites->getSiteById((int)$form->siteId)?->handle
                    ?? $sites->getPrimarySite()->handle,
            ],
            'form' => [
                'handle' => $form->handle,
                'name' => $form->name,
                'propagationMethod' => $form->propagationMethod->value,
                'allowSaveResume' => $form->allowSaveResume,
                // Full form-level settings (#226). redirectEntryId is an
                // install-local element id, so it travels as the target entry's URI.
                'postSubmitAction' => $form->postSubmitAction,
                'redirectEntry' => $this->exportRedirectEntry($form),
                'openDate' => $form->openDate?->format(\DateTime::ATOM),
                'closeDate' => $form->closeDate?->format(\DateTime::ATOM),
                'submissionLimit' => $form->submissionLimit,
                'submissionsPerUser' => $form->submissionsPerUser,
                'requireLogin' => $form->requireLogin,
                'guestLimitKey' => $form->guestLimitKey,
                'allowEditing' => $form->allowEditing,
                'editWindowMinutes' => $form->editWindowMinutes,
                'preventDuplicates' => $form->preventDuplicates,
                'duplicateWindowMinutes' => $form->duplicateWindowMinutes,
                'duplicateKey' => $form->duplicateKey,
                'useCustomTemplate' => $form->useCustomTemplate,
                'templatePath' => $form->templatePath,
                'content' => $this->exportFormContent($formId, $supportedSiteIds),
            ],
            'fields' => $this->exportFields($formId, $supportedSiteIds),
            'notifications' => $this->exportNotifications($formId),
            'integrations' => $this->exportIntegrations($formId),
        ];
    }

    /**
     * Export a form to a pretty-printed JSON string.
     */
    public function exportJson(Form $form): string
    {
        return (string)json_encode(
            $this->export($form),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * Validate and recreate a form from an export document, in one transaction.
     *
     * @param array<string, mixed> $data the decoded export document
     * @param array<string, mixed> $opts {mode: rename|replace|abort}
     * @throws InvalidArgumentException on a missing/unsupported schema version,
     *   a malformed document, or an `abort`-mode handle collision
     * @throws \Throwable if the transactional recreate fails (rolled back)
     */
    public function import(array $data, array $opts = []): ImportResult
    {
        $result = new ImportResult();
        $data = $this->migrateSchema($data, $result);

        $form = $data['form'] ?? null;
        if (!is_array($form) || !is_string($form['handle'] ?? null) || trim((string)$form['handle']) === '') {
            throw new InvalidArgumentException(Craft::t('simple-form', 'The import file is missing a form definition.'));
        }

        $mode = (string)($opts['mode'] ?? self::MODE_RENAME);
        if (!in_array($mode, self::MODES, true)) {
            $mode = self::MODE_RENAME;
        }

        $handle = $this->resolveHandle((string)$form['handle'], $mode, $result);

        $transaction = Craft::$app->getDb()->beginTransaction();
        try {
            $newForm = $this->createForm($form, $handle, $result);
            $this->importFields($newForm, is_array($data['fields'] ?? null) ? $data['fields'] : []);
            $this->importNotifications($newForm, is_array($data['notifications'] ?? null) ? $data['notifications'] : []);
            $this->importIntegrations($newForm, is_array($data['integrations'] ?? null) ? $data['integrations'] : [], $result);
            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        $result->form = $newForm;
        Plugin::getInstance()->getAudit()->log(AuditService::ACTION_FORM_IMPORT, AuditService::TARGET_FORM, (int)$newForm->id, (string)($newForm->title ?? $newForm->name));

        return $result;
    }

    /**
     * Decode a JSON export string and import it.
     *
     * @param array<string, mixed> $opts
     * @throws InvalidArgumentException on invalid JSON or document
     * @throws \Throwable on a failed recreate
     */
    public function importJson(string $json, array $opts = []): ImportResult
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new InvalidArgumentException(Craft::t('simple-form', 'The import file is not valid JSON.'));
        }

        return $this->import($decoded, $opts);
    }

    /**
     * Apply a form definition onto an **existing** form, in place and id-stable
     * (#225). The form's element id is kept and fields are reconciled by handle —
     * a matched field keeps its id (so its submissions and conditional references
     * survive), a new handle is inserted, and a handle missing from the file is
     * kept unless `$prune` is set. Even with `$prune`, a field that still holds
     * submission data is kept (with a warning) rather than silently dropped.
     *
     * The file is authoritative for structure and content (re-applying restores
     * both), so manage a code-defined form by editing its file — or edit it in
     * the CP and re-export. Runs in one transaction (via FieldSyncService).
     *
     * @param array<string, mixed> $data the decoded export document
     */
    public function applyToExistingForm(Form $form, array $data, bool $prune, ImportResult $result): void
    {
        $fields = is_array($data['fields'] ?? null) ? $data['fields'] : [];
        $formId = (int)$form->id;
        $sites = Craft::$app->getSites();
        $canonicalSiteId = (int)$form->siteId;
        $canonicalSite = $sites->getSiteById($canonicalSiteId) ?? $sites->getPrimarySite();

        // Apply form-level changes from the file (name, save-resume, per-site
        // content). Propagation is intentionally NOT changed on an existing form —
        // that re-propagates/removes site rows and is a deliberate CP action.
        if (is_array($data['form'] ?? null)) {
            $this->updateFormLevel($form, $data['form'], $result);
        }

        $idByHandle = FormContentHelper::fieldIdsByHandle($formId);
        $existingRows = FieldQueryHelper::fieldsForForm($formId, $canonicalSiteId);
        $typeByHandle = [];
        foreach ($existingRows as $row) {
            $typeByHandle[(string)$row['name']] = (string)$row['type'];
        }

        // File fields first, in file order. Inject the existing id for a matched
        // handle so the sync updates that row in place (keeping its id).
        $fileHandles = [];
        $items = [];
        foreach ($this->fieldsToBuilderItems($fields, $canonicalSite->handle) as $item) {
            $handle = (string)$item['handle'];
            if ($handle === '') {
                continue;
            }
            $fileHandles[$handle] = true;

            if (isset($idByHandle[$handle])) {
                $item['id'] = $idByHandle[$handle];
                // Field type is immutable once created; the sync keeps the stored
                // type, so flag a file/DB mismatch rather than silently ignoring it.
                if (isset($typeByHandle[$handle]) && $typeByHandle[$handle] !== (string)$item['type']) {
                    $result->warnings[] = Craft::t('simple-form', 'Field “{handle}” type cannot change ({from} → {to}); kept as {from}.', [
                        'handle' => $handle,
                        'from' => $typeByHandle[$handle],
                        'to' => (string)$item['type'],
                    ]);
                }
            }
            $items[] = $item;
        }

        // Fields in the DB but absent from the file: keep by default; with prune,
        // drop only those with no submission data.
        $withData = $this->fieldIdsWithSubmissionData($formId);
        foreach ($existingRows as $row) {
            $handle = (string)$row['name'];
            if (isset($fileHandles[$handle])) {
                continue;
            }
            $id = (int)$row['id'];

            if ($prune && !in_array($id, $withData, true)) {
                $result->warnings[] = Craft::t('simple-form', 'Pruned field “{handle}” (no submission data).', ['handle' => $handle]);
                continue; // omitted from items => the sync deletes it
            }
            if ($prune) {
                $result->warnings[] = Craft::t('simple-form', 'Kept field “{handle}”: it has submission data and was not pruned.', ['handle' => $handle]);
            }
            // Keep: re-add with its existing id, config and content (no-op update).
            $items[] = $this->existingRowToItem($row);
        }

        // One transactional sync owns the canonical site's structure + content.
        Plugin::getInstance()->getFieldSync()->sync($form, $items, $canonicalSiteId);

        // Overlay each sibling site's translated content from the file.
        $supportedSiteIds = array_filter(
            $form->supportedSiteIds() ?: [$canonicalSiteId],
            static fn(int $id): bool => $id !== $canonicalSiteId,
        );
        foreach ($supportedSiteIds as $siteId) {
            $site = $sites->getSiteById($siteId);
            if ($site === null) {
                continue;
            }
            $this->overlaySiteContent($formId, $siteId, $fields, $site->handle);
        }

        $result->form = $form;
        Plugin::getInstance()->getAudit()->log(AuditService::ACTION_FORM_IMPORT, AuditService::TARGET_FORM, $formId, (string)($form->title ?? $form->name));
    }

    /**
     * Apply the file's form-level definition (name, save-resume, per-site content)
     * onto an existing form and its sibling-site rows. Propagation is left as-is
     * (changing it on a live form re-propagates rows — a deliberate CP action).
     *
     * @param array<string, mixed> $formNode the export document's `form` node
     */
    private function updateFormLevel(Form $form, array $formNode, ImportResult $result): void
    {
        $sites = Craft::$app->getSites();
        $content = is_array($formNode['content'] ?? null) ? $formNode['content'] : [];
        $canonicalSite = $sites->getSiteById((int)$form->siteId) ?? $sites->getPrimarySite();

        $form->name = (string)($formNode['name'] ?? $form->name);
        $form->allowSaveResume = (bool)($formNode['allowSaveResume'] ?? $form->allowSaveResume);
        $this->applyFormContent($form, is_array($content[$canonicalSite->handle] ?? null) ? $content[$canonicalSite->handle] : []);
        $this->applyFormSettings($form, $formNode, $result);
        Craft::$app->getElements()->saveElement($form);

        foreach ($this->resolveSiteHandles(array_keys($content), $result) as $siteHandle) {
            if ($siteHandle === $canonicalSite->handle) {
                continue;
            }
            $site = $sites->getSiteByHandle($siteHandle);
            if ($site === null) {
                continue;
            }
            $sibling = Form::find()->id($form->id)->siteId($site->id)->status(null)->one();
            if ($sibling === null) {
                continue;
            }
            $this->applyFormContent($sibling, is_array($content[$siteHandle] ?? null) ? $content[$siteHandle] : []);
            Craft::$app->getElements()->saveElement($sibling);
        }
    }

    /**
     * Build a FieldSyncService builder item from an existing resolved field row,
     * folding its per-site option labels back into the config as `siteLabel` so
     * the sync round-trips the content unchanged.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function existingRowToItem(array $row): array
    {
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];
        unset($config['required']); // exposed as its own column

        $optionLabels = is_array($row['optionLabels'] ?? null) ? $row['optionLabels'] : [];
        if ($optionLabels !== [] && isset($config['options']) && is_array($config['options'])) {
            foreach ($config['options'] as &$opt) {
                if (is_array($opt) && isset($opt['value'])) {
                    $opt['siteLabel'] = (string)($optionLabels[(string)$opt['value']] ?? '');
                }
            }
            unset($opt);
        }

        return [
            'id' => (int)$row['id'],
            'type' => (string)$row['type'],
            'handle' => (string)$row['name'],
            'label' => (string)($row['label'] ?? $row['name'] ?? ''),
            'required' => (bool)($row['required'] ?? false),
            'helpText' => (string)($row['helpText'] ?? ''),
            'errorMessage' => (string)($row['errorMessage'] ?? ''),
            'config' => $config,
        ];
    }

    /**
     * Field ids that appear in at least one of the form's stored submissions
     * (data is keyed by `field_<id>`). Used so a prune never silently drops a
     * field that still holds answers.
     *
     * @return list<int>
     */
    private function fieldIdsWithSubmissionData(int $formId): array
    {
        $ids = [];
        $rows = (new Query())
            ->select(['data'])
            ->from('{{%simpleform_submissions}}')
            ->where(['formId' => $formId])
            ->column();

        foreach ($rows as $json) {
            $data = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
            if (!is_array($data)) {
                continue;
            }
            foreach (array_keys($data) as $key) {
                if (preg_match('/^field_(\d+)$/', (string)$key, $m) === 1) {
                    $ids[(int)$m[1]] = true;
                }
            }
        }

        return array_keys($ids);
    }

    // =========================================================================
    // Private Methods — Export
    // =========================================================================

    /**
     * Per-site shared-content map (title + email settings) keyed by site handle.
     *
     * @param int[] $siteIds
     * @return array<string, array<string, mixed>>
     */
    private function exportFormContent(int $formId, array $siteIds): array
    {
        $sites = Craft::$app->getSites();
        $content = [];

        foreach ($siteIds as $siteId) {
            $site = $sites->getSiteById($siteId);
            if ($site === null) {
                continue;
            }

            $form = Form::find()->id($formId)->siteId($siteId)->status(null)->one();
            if ($form === null) {
                continue;
            }

            $node = [];
            foreach (FormContentHelper::CONTENT_ATTRS as $attr) {
                $node[$attr] = $form->$attr;
            }
            $content[$site->handle] = $node;
        }

        return $content;
    }

    /**
     * Export the post-submit redirect target as a portable reference: an entry's
     * id is install-local, so travel the entry's URI instead. Null unless the form
     * redirects to an entry that resolves.
     *
     * @return array{uri: string}|null
     */
    private function exportRedirectEntry(Form $form): ?array
    {
        if ($form->postSubmitAction !== 'entry' || $form->redirectEntryId === null) {
            return null;
        }
        $entry = Entry::find()->id($form->redirectEntryId)->siteId('*')->status(null)->one();
        $uri = $entry instanceof Entry ? $entry->uri : null;
        return $uri !== null && $uri !== '' ? ['uri' => $uri] : null;
    }

    /**
     * Export every field handle-keyed with its decoded config (conditional logic
     * included) and per-site label/help-text/option-label/error-message content.
     *
     * @param int[] $siteIds
     * @return list<array<string, mixed>>
     */
    private function exportFields(int $formId, array $siteIds): array
    {
        $sites = Craft::$app->getSites();

        // Structural rows from the primary supported site (config is shared).
        $baseSiteId = $siteIds[0] ?? $sites->getPrimarySite()->id;
        $baseRows = FieldQueryHelper::fieldsForForm($formId, $baseSiteId);

        // Per-site content rows, keyed by field id then site handle.
        $contentBySite = [];
        foreach ($siteIds as $siteId) {
            $site = $sites->getSiteById($siteId);
            if ($site === null) {
                continue;
            }
            foreach (FieldQueryHelper::fieldsForForm($formId, $siteId) as $row) {
                $contentBySite[(int)$row['id']][$site->handle] = [
                    'label' => $row['label'],
                    'helpText' => $row['helpText'] ?? null,
                    'optionLabels' => !empty($row['optionLabels']) ? $row['optionLabels'] : null,
                    'errorMessage' => $row['errorMessage'] ?? null,
                ];
            }
        }

        $fields = [];
        foreach ($baseRows as $row) {
            $config = $row['config'];
            // `required` is exposed as its own column; drop the duplicate merged
            // into config by the query helper so the document stays canonical.
            unset($config['required']);

            $fields[] = [
                'handle' => (string)$row['name'],
                'type' => (string)$row['type'],
                'required' => (bool)$row['required'],
                'sortOrder' => (int)$row['sortOrder'],
                'config' => $config,
                'content' => $contentBySite[(int)$row['id']] ?? [],
            ];
        }

        return $fields;
    }

    /**
     * Export the form's notifications, dropping ids/formId/uid (regenerated on import).
     *
     * @return list<array<string, mixed>>
     */
    private function exportNotifications(int $formId): array
    {
        $notifications = [];
        foreach (Plugin::getInstance()->getNotifications()->getForForm($formId) as $notification) {
            $notifications[] = [
                'name' => $notification->name,
                'enabled' => $notification->enabled,
                'recipientType' => $notification->recipientType,
                'recipient' => $notification->recipient,
                'subject' => $notification->subject,
                'replyTo' => $notification->replyTo,
                'body' => $notification->body,
                'conditional' => $notification->conditional,
                'sortOrder' => $notification->sortOrder,
            ];
        }

        return $notifications;
    }

    /**
     * Export integration *references* (type + name + non-secret settings) with
     * every secret key redacted. The global definitions are shared install state,
     * not form definition, so only the reference travels.
     *
     * @return list<array<string, mixed>>
     */
    private function exportIntegrations(int $formId): array
    {
        $integrations = [];
        foreach (Plugin::getInstance()->getIntegrations()->getIntegrationsForForm($formId) as $integration) {
            $integrations[] = [
                'type' => $integration->type,
                'name' => $integration->name,
                'settings' => IntegrationsService::redactSecrets($integration->settings),
            ];
        }

        return $integrations;
    }

    // =========================================================================
    // Private Methods — Import
    // =========================================================================

    /**
     * Validate the document's schema version and migrate older versions up to the
     * current schema. Unknown future versions abort with a clear message.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    private function migrateSchema(array $data, ImportResult $result): array
    {
        $meta = is_array($data['_meta'] ?? null) ? $data['_meta'] : [];
        $version = isset($meta['schemaVersion']) ? (int)$meta['schemaVersion'] : 0;

        if ($version < 1) {
            throw new InvalidArgumentException(Craft::t('simple-form', 'The import file is missing a schema version.'));
        }

        if ($version > self::SCHEMA_VERSION) {
            throw new InvalidArgumentException(Craft::t(
                'simple-form',
                'This file was exported by a newer version of Simple Form (schema {version}). Please update the plugin before importing.',
                ['version' => $version],
            ));
        }

        // Upgrader chain: each entry migrates v(key) -> v(key+1). The map is
        // empty because every schema bump so far has been purely additive (a
        // newer reader ignores absent keys); a future breaking bump registers a
        // closure here, and any older document is walked forward step by step.
        foreach ($this->schemaUpgraders() as $from => $upgrade) {
            if ($version === $from) {
                $data = $upgrade($data);
                $version++;
                $result->addWarning(Craft::t('simple-form', 'Upgraded the import from schema {version}.', ['version' => $from]));
            }
        }

        return $data;
    }

    /**
     * The schema upgraders, keyed by the source version each one migrates *from*.
     * Empty: schema bumps so far are purely additive, so no document needs
     * upgrading; a future breaking bump adds `1 => fn($d) => ...` here.
     *
     * @return array<int, callable(array<string, mixed>): array<string, mixed>>
     */
    private function schemaUpgraders(): array
    {
        return [];
    }

    /**
     * Resolve the handle to create under, honouring the conflict mode.
     *
     * @throws InvalidArgumentException in abort mode when the handle exists
     */
    private function resolveHandle(string $handle, string $mode, ImportResult $result): string
    {
        $handle = trim($handle);
        if (!FormContentHelper::handleExists($handle)) {
            return $handle;
        }

        return match ($mode) {
            self::MODE_ABORT => throw new InvalidArgumentException(Craft::t(
                'simple-form',
                'A form with the handle “{handle}” already exists.',
                ['handle' => $handle],
            )),
            self::MODE_REPLACE => $this->replaceExisting($handle, $result),
            default => $this->uniqueHandle($handle),
        };
    }

    /**
     * Delete every existing form with the given handle (across all sites) so the
     * import can recreate it. Warns that the replaced form's submissions are lost.
     */
    private function replaceExisting(string $handle, ImportResult $result): string
    {
        $forms = Form::find()->handle($handle)->siteId('*')->status(null)->all();
        foreach ($forms as $form) {
            Craft::$app->getElements()->deleteElement($form, true);
        }

        $result->addWarning(Craft::t('simple-form', 'Replaced the existing “{handle}” form; its submissions were discarded.', ['handle' => $handle]));

        return $handle;
    }

    /**
     * Derive a unique handle by appending `-2`, `-3`, … until one is free.
     */
    private function uniqueHandle(string $handle): string
    {
        $suffix = 2;
        while (FormContentHelper::handleExists($candidate = "{$handle}-{$suffix}")) {
            $suffix++;
        }

        return $candidate;
    }

    /**
     * Create the Form element with shared + per-site content, resolving site
     * handles to local ids and skipping (with a warning) sites absent on target.
     *
     * @param array<string, mixed> $form the export document's `form` node
     */
    private function createForm(array $form, string $handle, ImportResult $result): Form
    {
        $sites = Craft::$app->getSites();
        $content = is_array($form['content'] ?? null) ? $form['content'] : [];

        // The canonical (first-created) site: prefer the export's source site if it
        // exists locally, else the primary site.
        $orderedHandles = $this->resolveSiteHandles(array_keys($content), $result);
        $canonicalHandle = $orderedHandles[0] ?? $sites->getPrimarySite()->handle;
        $canonicalSite = $sites->getSiteByHandle($canonicalHandle) ?? $sites->getPrimarySite();

        $element = new Form();
        $element->siteId = $canonicalSite->id;
        $element->name = (string)($form['name'] ?? $handle);
        $element->handle = $handle;
        $element->allowSaveResume = (bool)($form['allowSaveResume'] ?? false);
        $element->propagationMethod = $this->resolvePropagation((string)($form['propagationMethod'] ?? 'none'));
        $this->applyFormContent($element, $content[$canonicalHandle] ?? []);
        $this->applyFormSettings($element, $form, $result);

        if (!Craft::$app->getElements()->saveElement($element)) {
            throw new InvalidArgumentException(Craft::t(
                'simple-form',
                'Could not create the imported form: {errors}',
                ['errors' => implode(', ', $element->getFirstErrors())],
            ));
        }

        // Save remaining sites' translated content onto the propagated rows.
        foreach ($orderedHandles as $siteHandle) {
            if ($siteHandle === $canonicalHandle) {
                continue;
            }
            $site = $sites->getSiteByHandle($siteHandle);
            if ($site === null) {
                continue;
            }
            $sibling = Form::find()->id($element->id)->siteId($site->id)->status(null)->one();
            if ($sibling === null) {
                continue;
            }
            $this->applyFormContent($sibling, $content[$siteHandle] ?? []);
            Craft::$app->getElements()->saveElement($sibling);
        }

        return $element;
    }

    /**
     * Resolve exported site handles against local sites, preserving order and
     * warning about any that are absent on the target.
     *
     * @param array<int, string> $handles
     * @return list<string> the subset that exist locally
     */
    private function resolveSiteHandles(array $handles, ImportResult $result): array
    {
        $sites = Craft::$app->getSites();
        $resolved = [];
        foreach ($handles as $handle) {
            $handle = (string)$handle;
            if ($sites->getSiteByHandle($handle) !== null) {
                $resolved[] = $handle;
            } else {
                $result->addWarning(Craft::t('simple-form', 'Skipped content for the site “{handle}”, which does not exist on this install.', ['handle' => $handle]));
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, mixed> $content one site's content node
     */
    private function applyFormContent(Form $form, array $content): void
    {
        // title is special-cased: it falls back to the form's name when absent.
        $form->title = isset($content['title']) ? (string)$content['title'] : $form->name;
        foreach (FormContentHelper::CONTENT_ATTRS as $attr) {
            if ($attr !== 'title') {
                $form->$attr = $content[$attr] ?? null;
            }
        }
    }

    /**
     * Apply the file's full form-level settings (#226) onto a form. Each key is
     * applied only when present in the document, so a v1 document (which lacks
     * them) preserves the form's existing settings instead of resetting them.
     *
     * @param array<string, mixed> $node the export document's `form` node
     */
    private function applyFormSettings(Form $form, array $node, ImportResult $result): void
    {
        if (array_key_exists('postSubmitAction', $node) && in_array($node['postSubmitAction'], Form::POST_SUBMIT_ACTIONS, true)) {
            $form->postSubmitAction = (string)$node['postSubmitAction'];
        }
        if (array_key_exists('redirectEntry', $node)) {
            $form->redirectEntryId = $this->resolveRedirectEntry($node['redirectEntry'], $result);
        }
        if (array_key_exists('openDate', $node)) {
            $form->openDate = $this->parseDate($node['openDate']);
        }
        if (array_key_exists('closeDate', $node)) {
            $form->closeDate = $this->parseDate($node['closeDate']);
        }
        if (array_key_exists('submissionLimit', $node)) {
            $form->submissionLimit = $node['submissionLimit'] !== null ? (int)$node['submissionLimit'] : null;
        }
        if (array_key_exists('submissionsPerUser', $node)) {
            $form->submissionsPerUser = $node['submissionsPerUser'] !== null ? (int)$node['submissionsPerUser'] : null;
        }
        if (array_key_exists('requireLogin', $node)) {
            $form->requireLogin = (bool)$node['requireLogin'];
        }
        if (array_key_exists('guestLimitKey', $node) && in_array($node['guestLimitKey'], Form::GUEST_LIMIT_KEYS, true)) {
            $form->guestLimitKey = (string)$node['guestLimitKey'];
        }
        if (array_key_exists('allowEditing', $node)) {
            $form->allowEditing = (bool)$node['allowEditing'];
        }
        if (array_key_exists('editWindowMinutes', $node)) {
            $form->editWindowMinutes = (int)$node['editWindowMinutes'];
        }
        if (array_key_exists('preventDuplicates', $node)) {
            $form->preventDuplicates = (bool)$node['preventDuplicates'];
        }
        if (array_key_exists('duplicateWindowMinutes', $node)) {
            $form->duplicateWindowMinutes = (int)$node['duplicateWindowMinutes'];
        }
        if (array_key_exists('duplicateKey', $node) && in_array($node['duplicateKey'], Form::DUPLICATE_KEYS, true)) {
            $form->duplicateKey = (string)$node['duplicateKey'];
        }
        if (array_key_exists('useCustomTemplate', $node)) {
            $form->useCustomTemplate = (bool)$node['useCustomTemplate'];
        }
        if (array_key_exists('templatePath', $node)) {
            $form->templatePath = $node['templatePath'] !== null ? (string)$node['templatePath'] : null;
        }

        // A "redirect to entry" action with no resolvable target would fail
        // validation; degrade gracefully to the inline message instead of aborting
        // the whole import (resolveRedirectEntry already warned about the entry).
        if ($form->postSubmitAction === 'entry' && $form->redirectEntryId === null) {
            $form->postSubmitAction = 'message';
        }
    }

    /**
     * Resolve a portable redirect-entry reference (`{uri}`) to a local entry id,
     * warning (and returning null) when it can't be found on this install.
     *
     * @param mixed $ref
     */
    private function resolveRedirectEntry(mixed $ref, ImportResult $result): ?int
    {
        if (!is_array($ref) || trim((string)($ref['uri'] ?? '')) === '') {
            return null;
        }
        $uri = (string)$ref['uri'];
        $entry = Entry::find()->uri($uri)->siteId('*')->status(null)->one();
        if (!$entry instanceof Entry) {
            $result->addWarning(Craft::t('simple-form', 'Redirect entry “{uri}” was not found on this install; the post-submit redirect was left unset.', ['uri' => $uri]));
            return null;
        }
        return (int)$entry->id;
    }

    /**
     * Parse an exported ATOM date string back to a DateTime, or null.
     *
     * @param mixed $value
     */
    private function parseDate(mixed $value): ?\DateTime
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $date = DateTimeHelper::toDateTime($value);
        return $date instanceof \DateTime ? $date : null;
    }

    private function resolvePropagation(string $value): PropagationMethod
    {
        $method = PropagationMethod::tryFrom($value) ?? PropagationMethod::None;
        if (!in_array($method->value, Form::SUPPORTED_PROPAGATION_METHODS, true)) {
            return PropagationMethod::None;
        }

        return $method;
    }

    /**
     * Recreate the form's fields via {@see FieldSyncService} so every persistence
     * invariant holds, then overlay each sibling site's translated content. Field
     * handles travel verbatim so conditional rules re-bind correctly.
     *
     * @param array<int, mixed> $fields
     */
    private function importFields(Form $form, array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $sites = Craft::$app->getSites();
        $canonicalSiteId = (int)$form->siteId;
        $canonicalSite = $sites->getSiteById($canonicalSiteId) ?? $sites->getPrimarySite();

        // One sync owns structure (handles/types/config) and seeds every supported
        // site's content rows with the canonical site's labels (FieldSyncService
        // replaces the whole set in one transaction).
        $canonicalItems = $this->fieldsToBuilderItems($fields, $canonicalSite->handle);
        Plugin::getInstance()->getFieldSync()->sync($form, $canonicalItems, $canonicalSiteId);

        // Overlay sibling-site translations onto the rows the sync just created.
        $supportedSiteIds = array_filter(
            $form->supportedSiteIds() ?: [$canonicalSiteId],
            static fn(int $id): bool => $id !== $canonicalSiteId,
        );
        foreach ($supportedSiteIds as $siteId) {
            $site = $sites->getSiteById($siteId);
            if ($site === null) {
                continue;
            }
            $this->overlaySiteContent((int)$form->id, $siteId, $fields, $site->handle);
        }
    }

    /**
     * Write one sibling site's translated label/help-text/option-label/error-message
     * onto the field rows already created by the canonical sync, matched by handle.
     *
     * @param array<int, mixed> $fields
     */
    private function overlaySiteContent(int $formId, int $siteId, array $fields, string $siteHandle): void
    {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $idByHandle = FormContentHelper::fieldIdsByHandle($formId);

        foreach ($this->fieldsToBuilderItems($fields, $siteHandle) as $item) {
            $fieldId = $idByHandle[(string)$item['handle']] ?? null;
            if ($fieldId === null) {
                continue;
            }
            [, $optionLabels] = FieldSyncService::splitOptionLabels(
                is_array($item['config'] ?? null) ? $item['config'] : [],
            );
            $helpText = trim((string)($item['helpText'] ?? '')) ?: null;
            $errorMessage = trim((string)($item['errorMessage'] ?? '')) ?: null;

            $db->createCommand()->update('{{%simpleform_fields_sites}}', [
                'label' => (string)$item['label'],
                'helpText' => $helpText,
                'optionLabels' => $optionLabels ?: null,
                'errorMessage' => $errorMessage,
                'dateUpdated' => $now,
            ], ['fieldId' => $fieldId, 'siteId' => $siteId])->execute();
        }
    }

    /**
     * Map exported field rows to FieldSyncService's builder-item shape for one site.
     *
     * @param array<int, mixed> $fields
     * @return list<array<string, mixed>>
     */
    private function fieldsToBuilderItems(array $fields, string $siteHandle): array
    {
        $items = [];
        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }
            $content = is_array($field['content'] ?? null) ? ($field['content'][$siteHandle] ?? []) : [];
            $config = is_array($field['config'] ?? null) ? $field['config'] : [];

            // Fold per-site option labels back onto each option as `siteLabel` so
            // FieldSyncService splits them into this site's override map.
            $optionLabels = is_array($content['optionLabels'] ?? null) ? $content['optionLabels'] : [];
            if ($optionLabels !== [] && isset($config['options']) && is_array($config['options'])) {
                foreach ($config['options'] as &$opt) {
                    if (is_array($opt) && isset($opt['value'])) {
                        $opt['siteLabel'] = (string)($optionLabels[(string)$opt['value']] ?? '');
                    }
                }
                unset($opt);
            }

            $items[] = [
                'type' => (string)($field['type'] ?? ''),
                'handle' => (string)($field['handle'] ?? ''),
                'label' => (string)($content['label'] ?? $field['handle'] ?? ''),
                'required' => (bool)($field['required'] ?? false),
                'helpText' => (string)($content['helpText'] ?? ''),
                'errorMessage' => (string)($content['errorMessage'] ?? ''),
                'config' => $config,
            ];
        }

        return $items;
    }

    /**
     * Recreate the form's notifications from the export array.
     *
     * @param array<int, mixed> $notifications
     */
    private function importNotifications(Form $form, array $notifications): void
    {
        $service = Plugin::getInstance()->getNotifications();
        foreach ($notifications as $row) {
            if (!is_array($row)) {
                continue;
            }
            $model = new NotificationModel();
            $model->formId = (int)$form->id;
            $model->name = (string)($row['name'] ?? '');
            $model->enabled = (bool)($row['enabled'] ?? true);
            $model->recipientType = (string)($row['recipientType'] ?? NotificationModel::RECIPIENT_FIXED);
            $model->recipient = (string)($row['recipient'] ?? '');
            $model->subject = $row['subject'] ?? null;
            $model->replyTo = $row['replyTo'] ?? null;
            $model->body = $row['body'] ?? null;
            $model->conditional = is_array($row['conditional'] ?? null) ? $row['conditional'] : null;
            $model->sortOrder = isset($row['sortOrder']) ? (int)$row['sortOrder'] : null;
            $service->save($model);
        }
    }

    /**
     * Re-attach exported integration references. Matches a local global
     * integration by type+name; otherwise creates a *disabled* placeholder with
     * redacted secrets blanked and surfaces a `needsCredentials` warning, so an
     * integration is never silently enabled with empty credentials.
     *
     * @param array<int, mixed> $integrations
     */
    private function importIntegrations(Form $form, array $integrations, ImportResult $result): void
    {
        $service = Plugin::getInstance()->getIntegrations();
        $formId = (int)$form->id;

        // Index existing global definitions by type+name for an O(1) match.
        $existing = [];
        foreach ($service->getAllIntegrations() as $integration) {
            $existing[$this->integrationKey($integration->type, $integration->name)] = $integration;
        }

        foreach ($integrations as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string)($row['type'] ?? '');
            $name = (string)($row['name'] ?? '');
            if ($type === '' || $name === '') {
                continue;
            }

            $match = $existing[$this->integrationKey($type, $name)] ?? null;
            if ($match !== null && $match->id !== null) {
                $service->toggleFormIntegration($formId, $match->id);
                continue;
            }

            $placeholder = new IntegrationModel();
            $placeholder->type = $type;
            $placeholder->name = $name;
            $placeholder->enabled = false;
            $placeholder->settings = $this->blankRedacted(is_array($row['settings'] ?? null) ? $row['settings'] : []);

            if ($service->saveIntegration($placeholder) && $placeholder->id !== null) {
                $service->toggleFormIntegration($formId, $placeholder->id);
                $existing[$this->integrationKey($type, $name)] = $placeholder;
                $result->addWarning(Craft::t(
                    'simple-form',
                    'Integration “{name}” was imported disabled — add its credentials, then enable it.',
                    ['name' => $name],
                ));
            }
        }
    }

    /**
     * Replace redacted secret placeholders with empty strings so a placeholder
     * integration carries no fake secret value.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function blankRedacted(array $settings): array
    {
        foreach (IntegrationsService::SECRET_KEYS as $key) {
            if (($settings[$key] ?? null) === IntegrationsService::REDACTED) {
                $settings[$key] = '';
            }
        }

        return $settings;
    }

    private function integrationKey(string $type, string $name): string
    {
        return $type . "\0" . $name;
    }
}

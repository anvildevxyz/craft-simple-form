<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\stencils\Stencil;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Deep-copies a form definition into a brand-new form (#138): a new element with
 * a fresh, collision-safe handle, copied per-site content, copied fields with
 * NEW ids, copied notifications, and copied integration attachments. The copy
 * has zero submissions and no source submission is touched.
 *
 * Two entry points share the same write plumbing:
 *  - {@see self::duplicate()} sources the field set + content from an existing
 *    form (per site, so translations carry over).
 *  - {@see self::createFromStencil()} sources them from a built-in
 *    {@see Stencil} data template.
 *
 * Fields are recreated through {@see FieldSyncService::sync()} (not raw inserts)
 * so conditional rules re-resolve against the copy's own field handles, and
 * per-site option/label/error-message translations are written per site.
 *
 * @since 2.11.0
 * @author Fabian Haefliger
 */
class FormCloneService extends Component
{
    // =========================================================================
    // Private Properties
    // =========================================================================

    private const PIVOT_TABLE = '{{%simpleform_form_integrations}}';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Deep-copy an existing form into a new, independent one. Runs in a single
     * transaction; returns the saved copy.
     *
     * @param array<string,mixed> $overrides optional attribute overrides on the
     *   new form (e.g. ['name' => 'My copy'])
     * @throws \Throwable if the copy cannot be saved
     */
    public function duplicate(Form $source, array $overrides = []): Form
    {
        $sourceId = (int) $source->id;
        if ($sourceId === 0) {
            throw new InvalidArgumentException('Cannot duplicate an unsaved form.');
        }

        $siteIds = $this->supportedSiteIds($source);
        $primarySiteId = $this->primarySiteId($siteIds);

        // Per-site shared/translatable form content, read from the source.
        $contentBySite = [];
        foreach ($siteIds as $siteId) {
            $row = Form::find()->id($sourceId)->siteId($siteId)->status(null)->one();
            if ($row !== null) {
                $contentBySite[$siteId] = $this->contentFrom($row);
            }
        }

        // Per-site field sets in sync-item shape (carries this site's labels/
        // option labels/error messages).
        /** @var array<int,array<int,array<string,mixed>>> $fieldsBySite */
        $fieldsBySite = [];
        foreach ($siteIds as $siteId) {
            $fieldsBySite[$siteId] = $this->sourceFieldsToSyncItems($sourceId, $siteId);
        }

        $primaryContent = $contentBySite[$primarySiteId] ?? reset($contentBySite) ?: [];
        $name = (string) ($overrides['name'] ?? $this->copyName((string) $source->name));
        $handle = $this->uniqueHandle((string) $source->handle . '-copy');

        $notifications = Plugin::getInstance()->getNotifications()->getForForm($sourceId);
        $integrationIds = $this->attachedIntegrationIds($sourceId);

        return $this->build(
            name: $name,
            handle: $handle,
            propagationMethod: $source->propagationMethod,
            allowSaveResume: $source->allowSaveResume,
            primarySiteId: $primarySiteId,
            siteIds: $siteIds,
            primaryContent: $primaryContent,
            contentBySite: $contentBySite,
            fieldsBySite: $fieldsBySite,
            notifications: $notifications,
            integrationIds: $integrationIds,
            overrides: $overrides,
        );
    }

    /**
     * Instantiate a new form from a built-in (or contributed) stencil. The
     * stencil's field set + default notifications seed the copy on every
     * supported site of the primary site's propagation default (single-site).
     *
     * @throws \Throwable if the copy cannot be saved
     */
    public function createFromStencil(Stencil $stencil): Form
    {
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
        $siteIds = [$primarySiteId];

        $name = $stencil->name !== '' ? $stencil->name : ucfirst($stencil->handle);
        $handle = $this->uniqueHandle($stencil->handle);

        // The stencil's fields are authored in one language; seed every supported
        // site with the same set so the copy is consistent (per-site translation
        // happens later in the editor).
        /** @var array<int,array<int,array<string,mixed>>> $fieldsBySite */
        $fieldsBySite = [$primarySiteId => $stencil->fields];

        return $this->build(
            name: $name,
            handle: $handle,
            propagationMethod: \craft\enums\PropagationMethod::None,
            allowSaveResume: false,
            primarySiteId: $primarySiteId,
            siteIds: $siteIds,
            primaryContent: ['title' => $name, 'description' => $stencil->description],
            contentBySite: [],
            fieldsBySite: $fieldsBySite,
            notifications: $this->stencilNotifications($stencil),
            integrationIds: [],
            overrides: [],
        );
    }

    /**
     * Resolve a globally-unique form handle from a desired base, appending
     * `-copy`, `-copy-2`, `-copy-3`, … only as needed. The base itself is tried
     * first, so a non-colliding base is returned unchanged. Matching is
     * case-insensitive to mirror {@see Form::validateHandleUnique()}.
     */
    public function uniqueHandle(string $base): string
    {
        $base = trim($base) !== '' ? trim($base) : 'form';

        if (!$this->handleExists($base)) {
            return $base;
        }

        // Strip a trailing "-copy"/"-copy-N" so re-duplicating "foo-copy" yields
        // "foo-copy-2" rather than "foo-copy-copy".
        $root = preg_replace('/-copy(-\d+)?$/', '', $base) ?? $base;
        if ($root === '') {
            $root = $base;
        }

        $candidate = $root . '-copy';
        if (!$this->handleExists($candidate)) {
            return $candidate;
        }

        $n = 2;
        while ($this->handleExists($root . '-copy-' . $n)) {
            $n++;
        }

        return $root . '-copy-' . $n;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Shared write path for both duplicate and stencil: create + save the new
     * Form element, sync its fields per site, copy notifications, and copy
     * integration attachments — all in one transaction.
     *
     * @param list<int>                          $siteIds
     * @param array<string,mixed>                $primaryContent
     * @param array<int,array<string,mixed>>     $contentBySite siteId => content
     * @param array<int,array<int,array<string,mixed>>> $fieldsBySite siteId => sync items
     * @param list<NotificationModel>            $notifications
     * @param list<int>                          $integrationIds
     * @param array<string,mixed>                $overrides
     * @throws \Throwable
     */
    private function build(
        string $name,
        string $handle,
        \craft\enums\PropagationMethod $propagationMethod,
        bool $allowSaveResume,
        int $primarySiteId,
        array $siteIds,
        array $primaryContent,
        array $contentBySite,
        array $fieldsBySite,
        array $notifications,
        array $integrationIds,
        array $overrides,
    ): Form {
        $fieldSync = new FieldSyncService();
        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $form = new Form();
            $form->siteId = $primarySiteId;
            $form->name = $name;
            $form->handle = $handle;
            $form->propagationMethod = $propagationMethod;
            $form->allowSaveResume = $allowSaveResume;
            $this->applyContent($form, $primaryContent);
            foreach ($overrides as $attr => $value) {
                if (in_array($attr, ['title', 'description', 'emailTo', 'emailSubject', 'emailReplyTo', 'emailBody', 'name'], true)) {
                    $form->$attr = $value;
                }
            }

            if (!Craft::$app->getElements()->saveElement($form)) {
                throw new \RuntimeException('Could not save the new form: ' . implode(', ', $form->getFirstErrors()));
            }

            $newId = (int) $form->id;

            // Sync fields once per supported site so each site's translations are
            // written. The FIRST pass (primary site) creates the field rows; every
            // later pass must reuse those new ids (matched by handle) so sync
            // UPDATES that site's label/option labels instead of re-inserting and
            // wiping the earlier pass's per-site rows.
            $orderedSiteIds = $this->orderSitesPrimaryFirst($siteIds, $primarySiteId);
            $idByHandle = [];
            foreach ($orderedSiteIds as $siteId) {
                // Carry each non-primary site's translated form content (title +
                // email settings). The primary site was saved above; re-saving in
                // the sibling site context writes that site's _sites row.
                if ($siteId !== $primarySiteId && isset($contentBySite[$siteId])) {
                    $siteForm = Form::find()->id($newId)->siteId($siteId)->status(null)->one();
                    if ($siteForm !== null) {
                        $this->applyContent($siteForm, $contentBySite[$siteId]);
                        Craft::$app->getElements()->saveElement($siteForm);
                    }
                }

                $items = $this->stripIds($fieldsBySite[$siteId] ?? ($fieldsBySite[$primarySiteId] ?? []));
                if ($idByHandle !== []) {
                    $items = $this->applyFieldIds($items, $idByHandle);
                }
                $fieldSync->sync($form, $items, $siteId);
                if ($idByHandle === []) {
                    $idByHandle = $this->fieldIdsByHandle($newId);
                }
            }

            $this->copyNotifications($notifications, $newId);
            $this->copyIntegrationAttachments($integrationIds, $newId);

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);
        Plugin::getInstance()->getAudit()->log('form.duplicate', 'form', (int) $form->id, $name);

        return $form;
    }

    /**
     * Map a source form's stored field rows (for one site) into the sync-item
     * shape, including this site's option labels merged back as `siteLabel` so
     * sync re-splits them into the per-site override map.
     *
     * @return array<int,array<string,mixed>>
     */
    private function sourceFieldsToSyncItems(int $formId, int $siteId): array
    {
        $rows = FieldQueryHelper::fieldsForForm($formId, $siteId);

        return array_map(function(array $row): array {
            $config = is_array($row['config'] ?? null) ? $row['config'] : [];
            // FieldQueryHelper injects `required` into config; drop it so it
            // doesn't double up with the column the sync path writes.
            unset($config['required']);
            $optionLabels = is_array($row['optionLabels'] ?? null) ? $row['optionLabels'] : [];
            $config = $this->mergeSiteLabels($config, $optionLabels);

            return [
                'type' => (string) $row['type'],
                'handle' => (string) $row['name'],
                'label' => (string) ($row['label'] ?? $row['name']),
                'required' => (bool) $row['required'],
                'helpText' => (string) ($row['helpText'] ?? ''),
                'errorMessage' => (string) ($row['errorMessage'] ?? ''),
                'config' => $config,
            ];
        }, $rows);
    }

    /**
     * Re-attach a site's option-label overrides onto a field config's options as
     * the transient `siteLabel` the sync path expects.
     *
     * @param array<string,mixed>  $config
     * @param array<string,string> $optionLabels value => label
     * @return array<string,mixed>
     */
    private function mergeSiteLabels(array $config, array $optionLabels): array
    {
        if ($optionLabels === [] || !isset($config['options']) || !is_array($config['options'])) {
            return $config;
        }

        foreach ($config['options'] as &$opt) {
            if (is_array($opt) && isset($opt['value'])) {
                $opt['siteLabel'] = (string) ($optionLabels[(string) $opt['value']] ?? '');
            }
        }
        unset($opt);

        return $config;
    }

    /**
     * Drop any `id` from sync items so the sync path treats them as new fields
     * (the source ids belong to the source form).
     *
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function stripIds(array $items): array
    {
        return array_map(static function(array $item): array {
            unset($item['id']);
            return $item;
        }, $items);
    }

    /**
     * Re-key sync items with the copy's own field ids (matched by handle) so a
     * later per-site sync UPDATES the existing field rather than inserting a new
     * one.
     *
     * @param array<int,array<string,mixed>> $items
     * @param array<string,int>              $idByHandle
     * @return array<int,array<string,mixed>>
     */
    private function applyFieldIds(array $items, array $idByHandle): array
    {
        return array_map(static function(array $item) use ($idByHandle): array {
            $handle = (string) ($item['handle'] ?? '');
            if (isset($idByHandle[$handle])) {
                $item['id'] = $idByHandle[$handle];
            }
            return $item;
        }, $items);
    }

    /**
     * The copy's field ids keyed by handle, for re-targeting later site passes.
     *
     * @return array<string,int>
     */
    private function fieldIdsByHandle(int $formId): array
    {
        $rows = (new Query())
            ->select(['id', 'name'])
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['name']] = (int) $row['id'];
        }
        return $map;
    }

    /**
     * Order site ids with the primary site first so it owns the canonical
     * field-creation pass.
     *
     * @param list<int> $siteIds
     * @return list<int>
     */
    private function orderSitesPrimaryFirst(array $siteIds, int $primarySiteId): array
    {
        $ordered = array_values(array_filter($siteIds, static fn(int $id): bool => $id !== $primarySiteId));
        array_unshift($ordered, $primarySiteId);
        return $ordered;
    }

    /**
     * Re-save each source notification against the new form id with a fresh id
     * and uid, preserving sort order, recipient, condition, and body.
     *
     * @param list<NotificationModel> $notifications
     */
    private function copyNotifications(array $notifications, int $newFormId): void
    {
        $service = Plugin::getInstance()->getNotifications();
        foreach ($notifications as $source) {
            $copy = new NotificationModel();
            $copy->formId = $newFormId;
            $copy->name = $source->name;
            $copy->enabled = $source->enabled;
            $copy->recipientType = $source->recipientType;
            $copy->recipient = $source->recipient;
            $copy->subject = $source->subject;
            $copy->replyTo = $source->replyTo;
            $copy->body = $source->body;
            $copy->conditional = $source->conditional;
            $copy->sortOrder = $source->sortOrder;
            $service->save($copy);
        }
    }

    /**
     * Re-point the source form's integration attachments at the new form. The
     * global integration definitions (and their encrypted secrets) are NOT
     * cloned — both forms reference the same shared integration rows.
     *
     * @param list<int> $integrationIds
     */
    private function copyIntegrationAttachments(array $integrationIds, int $newFormId): void
    {
        if ($integrationIds === []) {
            return;
        }

        $db = Craft::$app->getDb();
        $now = Db::prepareDateForDb(new \DateTime());
        foreach ($integrationIds as $integrationId) {
            $db->createCommand()->insert(self::PIVOT_TABLE, [
                'formId' => $newFormId,
                'integrationId' => $integrationId,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }
    }

    /**
     * Turn a stencil's notification definitions into NotificationModels, filling
     * a blank fixed recipient with the system's default sender so an admin alert
     * works out of the box.
     *
     * @return list<NotificationModel>
     */
    private function stencilNotifications(Stencil $stencil): array
    {
        $defaultEmail = (string) (Craft::$app->getProjectConfig()->get('email.fromEmail') ?? '');
        $defaultEmail = (string) \craft\helpers\App::parseEnv($defaultEmail);

        $models = [];
        $sortOrder = 1;
        foreach ($stencil->notifications as $definition) {
            $model = new NotificationModel();
            $model->name = (string) ($definition['name'] ?? Craft::t('simple-form', 'Notification'));
            $model->recipientType = (string) ($definition['recipientType'] ?? NotificationModel::RECIPIENT_FIXED);
            $recipient = (string) ($definition['recipient'] ?? '');
            if ($model->recipientType === NotificationModel::RECIPIENT_FIXED && $recipient === '') {
                $recipient = $defaultEmail;
            }
            $model->recipient = $recipient;
            $model->subject = isset($definition['subject']) ? (string) $definition['subject'] : null;
            $model->replyTo = isset($definition['replyTo']) ? (string) $definition['replyTo'] : null;
            $model->body = isset($definition['body']) ? (string) $definition['body'] : null;
            $model->sortOrder = $sortOrder++;
            // A fixed recipient that couldn't resolve to anything is dropped so
            // the copy never carries an invalid (empty-recipient) notification.
            if ($model->recipientType === NotificationModel::RECIPIENT_FIXED && trim($model->recipient) === '') {
                continue;
            }
            $models[] = $model;
        }

        return $models;
    }

    /**
     * The ids of integrations attached to a form.
     *
     * @return list<int>
     */
    private function attachedIntegrationIds(int $formId): array
    {
        return Plugin::getInstance()->getIntegrations()->getAttachedIntegrationIds($formId);
    }

    /**
     * Per-site form content (title + translatable email settings) read off a
     * loaded Form.
     *
     * @return array<string,mixed>
     */
    private function contentFrom(Form $form): array
    {
        return [
            'title' => $form->title,
            'description' => $form->description,
            'emailTo' => $form->emailTo,
            'emailSubject' => $form->emailSubject,
            'emailReplyTo' => $form->emailReplyTo,
            'emailBody' => $form->emailBody,
        ];
    }

    /**
     * Copy the per-site content onto a Form element.
     *
     * @param array<string,mixed> $content
     */
    private function applyContent(Form $form, array $content): void
    {
        foreach (['title', 'description', 'emailTo', 'emailSubject', 'emailReplyTo', 'emailBody'] as $attr) {
            if (array_key_exists($attr, $content)) {
                $form->$attr = $content[$attr];
            }
        }
    }

    /**
     * Append " (copy)" to a form's display name, translated for display only
     * (the handle stays ASCII-safe).
     */
    private function copyName(string $name): string
    {
        return Craft::t('simple-form', '{name} (copy)', ['name' => $name]);
    }

    /**
     * The supported site ids for a form, falling back to its own site.
     *
     * @return list<int>
     */
    private function supportedSiteIds(Form $form): array
    {
        $ids = $form->supportedSiteIds();
        return $ids !== [] ? $ids : [(int) $form->siteId];
    }

    /**
     * The primary site id within a set, else the system primary.
     *
     * @param list<int> $siteIds
     */
    private function primarySiteId(array $siteIds): int
    {
        $primary = Craft::$app->getSites()->getPrimarySite()->id;
        if (in_array($primary, $siteIds, true)) {
            return $primary;
        }
        return $siteIds[0] ?? $primary;
    }

    /**
     * Whether a form handle already exists (case-insensitive).
     */
    private function handleExists(string $handle): bool
    {
        return (new Query())
            ->from('{{%simpleform_forms}}')
            ->where(['handle' => $handle])
            ->exists();
    }
}

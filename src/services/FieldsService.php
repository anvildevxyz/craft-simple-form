<?php

namespace anvildev\simpleform\services;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use yii\base\Component;

/**
 * Per-field create/update/delete against the two-table field schema: a shared
 * structural row in `{{%simpleform_fields}}` plus a per-site label/helpText row
 * in `{{%simpleform_fields_sites}}`.
 *
 * Single source of truth for the writes the CP AJAX field builder
 * ({@see \anvildev\simpleform\controllers\FieldsController}) and the MCP field
 * tools ({@see \anvildev\simpleform\mcp\tools\support\FieldOps}) both perform,
 * so the row shape, the JSON-column handling, the transaction, and the
 * form-structure cache invalidation are defined once instead of mirrored.
 *
 * Each caller keeps the parts that genuinely differ: request/arg parsing,
 * validation, which sites a new field seeds (passed in as `$siteIds`), and any
 * conditional-rule sanitising of the config. This service owns only the
 * transactional DB writes and the cache invalidation, and re-throws on failure
 * for the caller to map to its own error contract.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FieldsService extends Component
{
    /**
     * Insert a field: one structural row plus a per-site label/helpText row for
     * each given site. The caller resolves the site-id list from the form's
     * propagation method (including its own single-site fallback). Returns the
     * new field id.
     *
     * @param array<string, mixed> $config
     * @param int[] $siteIds
     */
    public function add(int $formId, string $type, string $handle, bool $required, array $config, string $label, string $helpText, array $siteIds): int
    {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');
        $helpText = $helpText !== '' ? $helpText : null;

        // [[...]]-quote the column: Yii interpolates the max() argument raw, so an
        // unquoted camelCase identifier is case-folded to "sortorder" on Postgres.
        $maxSort = (new Query())
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId])
            ->max('[[sortOrder]]') ?? 0;

        $fieldId = $db->transaction(function() use ($db, $formId, $type, $handle, $required, $config, $label, $helpText, $siteIds, $now, $maxSort): int {
            // Structural (shared) row. Pass the array as-is; Craft's json column
            // encodes it once — json_encode()ing here would double-encode it.
            $db->createCommand()->insert('{{%simpleform_fields}}', [
                'formId' => $formId,
                'type' => $type,
                'name' => $handle,
                'required' => $required,
                'config' => $config,
                'sortOrder' => $maxSort + 1,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();

            $newId = (int) $db->getLastInsertID();

            foreach ($siteIds as $siteId) {
                $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
                    'fieldId' => $newId,
                    'siteId' => $siteId,
                    'label' => $label,
                    'helpText' => $helpText,
                    'dateCreated' => $now,
                    'dateUpdated' => $now,
                    'uid' => StringHelper::UUID(),
                ])->execute();
            }

            return $newId;
        });

        Plugin::getInstance()->getFormStructure()->invalidate($formId);

        return $fieldId;
    }

    /**
     * Update a field: structural columns once (no site filter), and the per-site
     * label/helpText upserted for the given site only.
     *
     * @param array<string, mixed> $config
     */
    public function update(int $fieldId, int $formId, int $siteId, string $handle, bool $required, array $config, string $label, string $helpText): void
    {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');
        $helpText = $helpText !== '' ? $helpText : null;

        $db->transaction(function() use ($db, $fieldId, $siteId, $handle, $required, $config, $label, $helpText, $now): void {
            $db->createCommand()->update('{{%simpleform_fields}}', [
                'name' => $handle,
                'required' => $required,
                'config' => $config,
                'dateUpdated' => $now,
            ], ['id' => $fieldId])->execute();

            $db->createCommand()->upsert('{{%simpleform_fields_sites}}', [
                'fieldId' => $fieldId,
                'siteId' => $siteId,
                'label' => $label,
                'helpText' => $helpText,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ], [
                'label' => $label,
                'helpText' => $helpText,
                'dateUpdated' => $now,
            ])->execute();
        });

        Plugin::getInstance()->getFormStructure()->invalidate($formId);
    }

    /**
     * Delete a field's structural row; the per-site rows cascade via FK.
     */
    public function delete(int $fieldId, int $formId): void
    {
        Craft::$app->getDb()->createCommand()->delete('{{%simpleform_fields}}', ['id' => $fieldId])->execute();
        Plugin::getInstance()->getFormStructure()->invalidate($formId);
    }

    /**
     * Validate the structural input for a field add/edit — the rules the CP
     * field builder and the MCP field tools share: label + handle presence, the
     * handle format and its per-form uniqueness, a registered type, and a
     * non-empty options set for the choice types.
     *
     * Returns errors keyed by input name, each a `{key, params}` pair the caller
     * renders in its own contract — the CP through `Craft::t()`, the MCP as raw
     * English — so the rule logic lives once. The `key` doubles as the
     * `simple-form` translation key. Caller-specific checks (e.g. the MCP's
     * conditional-logic validation) stay with the caller.
     *
     * @param array<string, mixed> $config
     * @return array<string, list<array{key: string, params: array<string, int|string>}>>
     */
    public function validateInput(string $type, ?string $label, ?string $handle, array $config, int $formId, ?int $excludeFieldId): array
    {
        $errors = [];

        if (empty($label)) {
            $errors['label'][] = ['key' => 'Label is required', 'params' => []];
        }

        if (empty($handle)) {
            $errors['handle'][] = ['key' => 'Handle is required', 'params' => []];
        } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
            $errors['handle'][] = ['key' => 'Handle must start with a letter or underscore, and contain only alphanumeric characters and underscores', 'params' => []];
        } else {
            $dupQuery = (new Query())
                ->from('{{%simpleform_fields}}')
                ->where(['formId' => $formId, 'name' => $handle]);
            if ($excludeFieldId !== null) {
                $dupQuery->andWhere(['not', ['id' => $excludeFieldId]]);
            }
            if ($dupQuery->exists()) {
                $errors['handle'][] = ['key' => 'A field with this handle already exists in this form', 'params' => []];
            }
        }

        if (!in_array($type, Plugin::getInstance()->getFieldTypeRegistry()->typeHandles(), true)) {
            $errors['type'][] = ['key' => 'Invalid field type', 'params' => []];
        }

        if (in_array($type, FieldTypeRegistry::OPTION_TYPES, true)
            && (empty($config['options']) || !is_array($config['options']))) {
            $errors['config'][] = ['key' => '{type} fields must have at least one option', 'params' => ['type' => $type]];
        }

        return $errors;
    }

    /**
     * The site ids a form's fields should exist on, derived from the form's
     * propagation method, with a caller-supplied fallback when the form can't be
     * loaded or supports no sites. Shared by the CP field builder (fallback: the
     * current site) and the MCP field tools (fallback: the primary site).
     *
     * The all-sites/all-status load is deliberate: Form is multi-site, so a
     * field's site set must reflect every site the form propagates to, not just
     * the request's current site.
     *
     * @return list<int>
     */
    public function supportedSiteIds(int $formId, int $fallbackSiteId): array
    {
        $form = Form::find()->id($formId)->siteId('*')->status(null)->one();
        if (!$form instanceof Form) {
            return [$fallbackSiteId];
        }

        $ids = $form->supportedSiteIds();
        return $ids !== [] ? $ids : [$fallbackSiteId];
    }

    /**
     * Transactionally rewrite field sort order to match the given id order
     * (1-based by position), then invalidate the affected form-structure
     * cache(s) — the single source of truth for the reorder write both the CP
     * field builder ({@see \anvildev\simpleform\controllers\FieldsController})
     * and the MCP field tools ({@see \anvildev\simpleform\mcp\tools\support\FieldOps})
     * perform.
     *
     * The caller chooses the scope. When `$formId` is given (the CP path, which
     * pre-validates that every id belongs to one form), each update is
     * constrained to that form — a stray id from another form can't be moved —
     * and only that form's cache is invalidated. When null (the MCP path),
     * fields are matched by id alone and every distinct affected form's cache is
     * cleared. Re-throws on failure for the caller's error contract.
     *
     * @param list<int> $orderedFieldIds the field ids in their new order
     */
    public function reorder(array $orderedFieldIds, ?int $formId = null): void
    {
        $orderedFieldIds = array_values($orderedFieldIds);
        if ($orderedFieldIds === []) {
            return;
        }

        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $db->transaction(function() use ($db, $orderedFieldIds, $formId, $now): void {
            foreach ($orderedFieldIds as $index => $fieldId) {
                $condition = ['id' => $fieldId];
                if ($formId !== null) {
                    $condition['formId'] = $formId;
                }
                $db->createCommand()->update('{{%simpleform_fields}}', [
                    'sortOrder' => $index + 1,
                    'dateUpdated' => $now,
                ], $condition)->execute();
            }
        });

        // Reorder changes the rendered field order, so the cached structure of
        // every affected form must be invalidated.
        $formIds = $formId !== null ? [$formId] : (new Query())
            ->select(['formId'])
            ->distinct()
            ->from('{{%simpleform_fields}}')
            ->where(['id' => $orderedFieldIds])
            ->column();

        $formStructure = Plugin::getInstance()->getFormStructure();
        foreach ($formIds as $affectedFormId) {
            $formStructure->invalidate((int) $affectedFormId);
        }
    }
}

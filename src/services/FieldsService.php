<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Per-field create/update/delete against the two-table field schema: a shared
 * structural row in `{{%simpleform_fields}}` plus a per-site label/helpText row
 * in `{{%simpleform_fields_sites}}`.
 *
 * Single source of truth for the writes the CP AJAX field builder
 * ({@see \fabianhaef\simpleform\controllers\FieldsController}) and the MCP field
 * tools ({@see \fabianhaef\simpleform\mcp\tools\support\FieldOps}) both perform,
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
 * @since 2.12.0
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

        $maxSort = (new Query())
            ->select(['sortOrder'])
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId])
            ->max('sortOrder') ?? 0;

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
}

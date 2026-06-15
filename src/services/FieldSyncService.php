<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Applies a complete, ordered set of form fields in one transaction: inserts new
 * fields, updates existing ones, fixes sort order, and deletes any that were
 * removed. Backs the batch-saved CP field builder (the posted `fieldsData` JSON),
 * mirroring the per-field logic in {@see \fabianhaef\simpleform\controllers\FieldsController}.
 */
class FieldSyncService extends Component
{
    /**
     * Validate the posted field set without touching the database. Uniqueness is
     * checked within the set itself, since the set fully replaces the form's fields.
     *
     * @param array<int,array<string,mixed>> $items
     * @return string[] human-readable error messages (empty when valid)
     */
    public function validate(array $items): array
    {
        $errors = [];
        $seenHandles = [];

        foreach ($items as $i => $item) {
            $pos = $i + 1;
            $label = trim((string)($item['label'] ?? ''));
            $handle = trim((string)($item['handle'] ?? ''));
            $type = (string)($item['type'] ?? '');
            $name = $label !== '' ? $label : ($handle !== '' ? $handle : "#$pos");

            if ($label === '') {
                $errors[] = Craft::t('simple-form', 'Field {name}: label is required.', ['name' => "#$pos"]);
            }

            if ($handle === '') {
                $errors[] = Craft::t('simple-form', 'Field {name}: handle is required.', ['name' => $name]);
            } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
                $errors[] = Craft::t('simple-form', 'Field {name}: handle must start with a letter or underscore and contain only letters, numbers and underscores.', ['name' => $name]);
            } else {
                $key = strtolower($handle);
                if (isset($seenHandles[$key])) {
                    $errors[] = Craft::t('simple-form', 'Duplicate field handle “{handle}”.', ['handle' => $handle]);
                }
                $seenHandles[$key] = true;
            }

            if (!in_array($type, Plugin::getInstance()->getFieldTypeRegistry()->typeHandles(), true)) {
                $errors[] = Craft::t('simple-form', 'Field {name}: invalid type.', ['name' => $name]);
            }

            if (in_array($type, FieldTypeRegistry::OPTION_TYPES, true)) {
                $options = $item['config']['options'] ?? null;
                if (empty($options) || !is_array($options)) {
                    $errors[] = Craft::t('simple-form', 'Field {name}: needs at least one option.', ['name' => $name]);
                }
            }
        }

        return $errors;
    }

    /**
     * Replace the form's fields with the posted set, in one transaction.
     *
     * @param array<int,array<string,mixed>> $items in display (sort) order
     */
    public function sync(Form $form, array $items, int $currentSiteId): void
    {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');
        $formId = (int)$form->id;

        $supportedSiteIds = $this->supportedSiteIds($form, $currentSiteId);

        $existingIds = array_map(
            'intval',
            (new Query())->select(['id'])->from('{{%simpleform_fields}}')->where(['formId' => $formId])->column()
        );

        $keptIds = [];
        $transaction = $db->beginTransaction();
        try {
            foreach ($items as $index => $item) {
                $sortOrder = $index + 1;
                $type = (string)$item['type'];
                $handle = trim((string)$item['handle']);
                $label = trim((string)$item['label']);
                $required = !empty($item['required']);
                $helpText = trim((string)($item['helpText'] ?? '')) ?: null;
                $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                // Strip per-option `siteLabel` out of the shared config into a
                // value => label map persisted on the current site only. Because
                // each translation rides with its option, add/remove/reorder keeps
                // labels aligned to the right value and orphans simply drop out.
                [$config, $optionLabels] = self::splitOptionLabels($config);
                $rawId = $item['id'] ?? null;
                $id = is_numeric($rawId) ? (int)$rawId : null;

                if ($id !== null && in_array($id, $existingIds, true)) {
                    // Update structural columns (type is immutable once created) + order.
                    $db->createCommand()->update('{{%simpleform_fields}}', [
                        'name' => $handle,
                        'required' => $required,
                        'config' => $config,
                        'sortOrder' => $sortOrder,
                        'dateUpdated' => $now,
                    ], ['id' => $id])->execute();

                    // Per-site translatable label/helpText/option labels for the current site only.
                    $db->createCommand()->upsert('{{%simpleform_fields_sites}}', [
                        'fieldId' => $id,
                        'siteId' => $currentSiteId,
                        'label' => $label,
                        'helpText' => $helpText,
                        'optionLabels' => $optionLabels ?: null,
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => StringHelper::UUID(),
                    ], [
                        'label' => $label,
                        'helpText' => $helpText,
                        'optionLabels' => $optionLabels ?: null,
                        'dateUpdated' => $now,
                    ])->execute();

                    $keptIds[] = $id;
                } else {
                    $db->createCommand()->insert('{{%simpleform_fields}}', [
                        'formId' => $formId,
                        'type' => $type,
                        'name' => $handle,
                        'required' => $required,
                        // Pass the array; the json column encodes once (json_encode here double-encodes).
                        'config' => $config,
                        'sortOrder' => $sortOrder,
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => StringHelper::UUID(),
                    ])->execute();

                    $newId = (int)$db->getLastInsertID();
                    foreach ($supportedSiteIds as $siteId) {
                        $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
                            'fieldId' => $newId,
                            'siteId' => $siteId,
                            'label' => $label,
                            'helpText' => $helpText,
                            // Option labels were authored on the editing site; other
                            // sites fall back to the source labels until translated.
                            'optionLabels' => $siteId === $currentSiteId ? ($optionLabels ?: null) : null,
                            'dateCreated' => $now,
                            'dateUpdated' => $now,
                            'uid' => StringHelper::UUID(),
                        ])->execute();
                    }

                    $keptIds[] = $newId;
                }
            }

            // Delete removed fields; their _sites rows cascade via FK.
            $toDelete = array_values(array_diff($existingIds, $keptIds));
            if ($toDelete) {
                $db->createCommand()->delete('{{%simpleform_fields}}', ['id' => $toDelete])->execute();
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        // The field set changed (add/edit/reorder/delete), so drop the cached
        // structure for this form across all sites.
        Plugin::getInstance()->getFormStructure()->invalidate($formId);
    }

    /**
     * Split a posted field config into the shared config (canonical option
     * `value` + source `label`) and a per-site `value => label` override map,
     * pulling each option's transient `siteLabel` out of the shared config.
     *
     * Pure and side-effect free so it can be unit-tested without a DB. Only
     * non-empty translations survive, so removed/renamed options leave no orphan
     * entries in the returned map.
     *
     * @param array<string,mixed> $config
     * @return array{0: array<string,mixed>, 1: array<string,string>} [cleanConfig, optionLabels]
     */
    public static function splitOptionLabels(array $config): array
    {
        if (!isset($config['options']) || !is_array($config['options'])) {
            return [$config, []];
        }

        $optionLabels = [];
        foreach ($config['options'] as &$opt) {
            if (!is_array($opt)) {
                continue;
            }
            $siteLabel = isset($opt['siteLabel']) ? trim((string)$opt['siteLabel']) : '';
            if ($siteLabel !== '' && isset($opt['value'])) {
                $optionLabels[(string)$opt['value']] = $siteLabel;
            }
            // Never persist the transient translation field on the shared config.
            unset($opt['siteLabel']);
        }
        unset($opt);

        return [$config, $optionLabels];
    }

    /**
     * Site IDs a new field should be seeded on, from the form's propagation method.
     *
     * @return int[]
     */
    private function supportedSiteIds(Form $form, int $currentSiteId): array
    {
        return $form->supportedSiteIds() ?: [$currentSiteId];
    }
}

<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\ConditionalEvaluator;
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

        $layoutTypes = Plugin::getInstance()->getFieldTypeRegistry()->layoutTypeHandles();

        foreach ($items as $i => $item) {
            $pos = $i + 1;
            $label = trim((string)($item['label'] ?? ''));
            $handle = trim((string)($item['handle'] ?? ''));
            $type = (string)($item['type'] ?? '');
            $name = $label !== '' ? $label : ($handle !== '' ? $handle : "#$pos");
            $isLayout = in_array($type, $layoutTypes, true);

            // Layout blocks (heading/divider/html) carry no user-facing label —
            // their content is the heading text / divider label / HTML body — so
            // only input fields require a label.
            if ($label === '' && !$isLayout) {
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

        return array_merge($errors, self::conditionalSetErrors($items));
    }

    /**
     * Validate conditional rules across a full field set: a field may not
     * reference itself, and the dependency graph must be acyclic. References to
     * handles not in the set are not errors — they are pruned on save (a target
     * field removed in the same edit). Self-reference and cycles are hard errors
     * because they have no sensible runtime meaning.
     *
     * Public + static so the MCP single-field write path can validate against
     * the form's full set with the same rules as the CP batch save.
     *
     * @param array<int,array<string,mixed>> $items
     * @return string[]
     */
    public static function conditionalSetErrors(array $items): array
    {
        $errors = [];
        $present = [];
        foreach ($items as $item) {
            $handle = trim((string)($item['handle'] ?? ''));
            if ($handle !== '') {
                $present[$handle] = true;
            }
        }

        $graph = [];
        foreach ($items as $i => $item) {
            $handle = trim((string)($item['handle'] ?? ''));
            $label = trim((string)($item['label'] ?? ''));
            $name = $label !== '' ? $label : ($handle !== '' ? $handle : '#' . ($i + 1));
            $config = is_array($item['config'] ?? null) ? $item['config'] : [];
            $refs = ConditionalEvaluator::referencedFields($config);

            if ($handle !== '' && in_array($handle, $refs, true)) {
                $errors[] = Craft::t('simple-form', 'Field {name}: a condition cannot reference the field itself.', ['name' => $name]);
            }

            // Edges only to handles present in the set (others are pruned on save).
            $graph[$handle] = array_values(array_filter(
                $refs,
                static fn($ref) => $ref !== $handle && isset($present[$ref])
            ));
        }

        if (self::hasCycle($graph)) {
            $errors[] = Craft::t('simple-form', 'Conditional rules form a circular dependency between fields. Remove one of the conditions.');
        }

        return $errors;
    }

    /**
     * Depth-first cycle detection over a handle => [referenced handles] graph.
     *
     * @param array<string, string[]> $graph
     */
    private static function hasCycle(array $graph): bool
    {
        $state = []; // 0/unset = unvisited, 1 = on stack, 2 = done
        foreach (array_keys($graph) as $node) {
            if (self::dfsHasCycle((string)$node, $graph, $state)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, string[]> $graph
     * @param array<string, int> $state
     */
    private static function dfsHasCycle(string $node, array $graph, array &$state): bool
    {
        $current = $state[$node] ?? 0;
        if ($current === 1) {
            return true;
        }
        if ($current === 2) {
            return false;
        }

        $state[$node] = 1;
        foreach ($graph[$node] ?? [] as $next) {
            if (self::dfsHasCycle($next, $graph, $state)) {
                return true;
            }
        }
        $state[$node] = 2;

        return false;
    }

    /**
     * Whether the posted set would create or change an HTML layout block's body
     * for the editing site, requiring the `editHtmlBlocks` permission.
     *
     * A user lacking that permission may still reorder or delete an existing
     * HTML block, and may leave its body untouched — only a new block with a
     * body, or a changed body, is gated. The body lives in the per-site
     * `helpText` column (no schema change). The check loads the stored body for
     * each existing HTML block so an unchanged save is never blocked.
     *
     * @param array<int,array<string,mixed>> $items the posted field set
     */
    public function htmlBlockBodyChanged(array $items, int $currentSiteId): bool
    {
        foreach ($items as $item) {
            if ((string)($item['type'] ?? '') !== 'html') {
                continue;
            }

            $body = trim((string)($item['helpText'] ?? ''));
            $rawId = $item['id'] ?? null;
            $id = is_numeric($rawId) ? (int)$rawId : null;

            // New block: only gated when it actually carries a body.
            if ($id === null) {
                if ($body !== '') {
                    return true;
                }
                continue;
            }

            $stored = (new Query())
                ->select(['helpText'])
                ->from('{{%simpleform_fields_sites}}')
                ->where(['fieldId' => $id, 'siteId' => $currentSiteId])
                ->scalar();

            if (trim((string)$stored) !== $body) {
                return true;
            }
        }

        return false;
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

        // Handles present in the saved set — conditional rules referencing a
        // handle outside this set (e.g. a target field removed in the same
        // edit) are pruned so no rule points at a field that no longer exists.
        $validHandles = [];
        foreach ($items as $item) {
            $h = trim((string)($item['handle'] ?? ''));
            if ($h !== '') {
                $validHandles[$h] = true;
            }
        }

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
                // Optional per-site validation message override (null falls back to
                // the field type's localized default at submit time).
                $errorMessage = trim((string)($item['errorMessage'] ?? '')) ?: null;
                $config = is_array($item['config'] ?? null) ? $item['config'] : [];
                // Strip per-option `siteLabel` out of the shared config into a
                // value => label map persisted on the current site only. Because
                // each translation rides with its option, add/remove/reorder keeps
                // labels aligned to the right value and orphans simply drop out.
                [$config, $optionLabels] = self::splitOptionLabels($config);
                // Drop conditional rules that point at a removed field or at the
                // field itself, so persisted rules only ever reference live peers.
                $config = self::sanitizeConditional($config, $validHandles, trim((string)$item['handle']));
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
                        'errorMessage' => $errorMessage,
                        'dateCreated' => $now,
                        'dateUpdated' => $now,
                        'uid' => StringHelper::UUID(),
                    ], [
                        'label' => $label,
                        'helpText' => $helpText,
                        'optionLabels' => $optionLabels ?: null,
                        'errorMessage' => $errorMessage,
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
                            // The override applies to the editing site; other sites
                            // fall back to the localized default until customized.
                            'errorMessage' => $siteId === $currentSiteId ? $errorMessage : null,
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
     * Remove conditional rules whose target handle is not in the saved set, or
     * is the field's own handle. If a rule set ends up empty its block is
     * dropped; if the whole `conditional` config ends up inert it is removed.
     *
     * Pure and side-effect free for unit testing.
     *
     * @param array<string,mixed> $config
     * @param array<string,bool> $validHandles handle => true for handles in the set
     * @return array<string,mixed>
     */
    public static function sanitizeConditional(array $config, array $validHandles, string $ownHandle): array
    {
        if (!isset($config['conditional']) || !is_array($config['conditional'])) {
            return $config;
        }

        $conditional = $config['conditional'];
        $keep = static function(array $rules) use ($validHandles, $ownHandle): array {
            $out = [];
            foreach ($rules as $rule) {
                if (!is_array($rule)) {
                    continue;
                }
                $target = (string)($rule['field'] ?? '');
                if ($target !== '' && $target !== $ownHandle && isset($validHandles[$target])) {
                    $out[] = $rule;
                }
            }
            return $out;
        };

        if (isset($conditional['rules']) && is_array($conditional['rules'])) {
            $conditional['rules'] = $keep($conditional['rules']);
        }
        if (isset($conditional['required']['rules']) && is_array($conditional['required']['rules'])) {
            $conditional['required']['rules'] = $keep($conditional['required']['rules']);
            if ($conditional['required']['rules'] === []) {
                unset($conditional['required']);
            }
        }

        // If neither block carries any usable rule, drop the conditional config
        // entirely so an inert block never lingers in storage.
        $hasVisibility = !empty($conditional['rules']);
        $hasRequired = !empty($conditional['required']['rules']);
        if (!$hasVisibility && !$hasRequired) {
            unset($config['conditional']);
        } else {
            $config['conditional'] = $conditional;
        }

        return $config;
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

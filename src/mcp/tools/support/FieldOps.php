<?php

namespace fabianhaef\simpleform\mcp\tools\support;

use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\FieldTypeRegistry;

/**
 * Shared field add/edit/reorder/delete logic for the MCP form-management tools.
 *
 * This deliberately mirrors {@see \fabianhaef\simpleform\controllers\FieldsController}
 * — the same committed CP path — so that fields created/edited/reordered/deleted
 * by an agent run the SAME structural-row + per-site label/helpText writes, the
 * SAME validation, and the SAME form-structure cache invalidation
 * ({@see \fabianhaef\simpleform\services\FormStructureService::invalidate()}).
 *
 * It is a thin adapter, not new business logic: nothing here is reachable except
 * through a forms:manage-scoped tool, and every write goes through the existing
 * tables/cache the CP uses.
 */
final class FieldOps
{
    /**
     * The set of valid field type handles, sourced from the field-type registry
     * so the tool surface stays in sync with the registered types.
     *
     * @return list<string>
     */
    public static function validTypes(): array
    {
        return Plugin::getInstance()->getFieldTypeRegistry()->typeHandles();
    }

    /**
     * JSON Schema fragment for the field-type enum, generated from the registry.
     *
     * @return array<string, mixed>
     */
    public static function typeSchema(): array
    {
        $types = Plugin::getInstance()->getFieldTypeRegistry()->getAllFieldTypes();
        $descriptions = [];
        foreach ($types as $type => $meta) {
            $descriptions[] = sprintf('%s (%s)', $type, $meta['label']);
        }

        return [
            'type' => 'string',
            'enum' => array_keys($types),
            'description' => 'The field type. One of: ' . implode(', ', $descriptions) . '. '
                . 'select/checkbox/radio fields require a non-empty config.options array of {value,label} entries.',
        ];
    }

    /**
     * Validate field input exactly as the CP does (label/handle/type/options),
     * returning a Craft-style errors array (empty when valid).
     *
     * @param array<string, mixed> $config
     * @return array<string, list<string>>
     */
    public static function validate(string $type, ?string $label, ?string $handle, array $config, int $formId, ?int $excludeFieldId): array
    {
        $errors = [];

        if (empty($label)) {
            $errors['label'][] = 'Label is required';
        }

        if (empty($handle)) {
            $errors['handle'][] = 'Handle is required';
        } elseif (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
            $errors['handle'][] = 'Handle must start with a letter or underscore, and contain only alphanumeric characters and underscores';
        } else {
            $dupQuery = (new Query())
                ->from('{{%simpleform_fields}}')
                ->where(['formId' => $formId, 'name' => $handle]);
            if ($excludeFieldId !== null) {
                $dupQuery->andWhere(['not', ['id' => $excludeFieldId]]);
            }
            if ($dupQuery->exists()) {
                $errors['handle'][] = 'A field with this handle already exists in this form';
            }
        }

        if (!in_array($type, self::validTypes(), true)) {
            $errors['type'][] = 'Invalid field type';
        }

        if (in_array($type, FieldTypeRegistry::OPTION_TYPES, true)) {
            if (empty($config['options']) || !is_array($config['options'])) {
                $errors['config'][] = $type . ' fields must have at least one option';
            }
        }

        return $errors;
    }

    /**
     * Add a field to a form. Mirrors FieldsController::actionAdd: one structural
     * row plus a per-site label/helpText row for every site the form supports.
     *
     * @param array<string, mixed> $config
     * @return int the new field id
     */
    public static function add(int $formId, string $type, string $handle, string $label, bool $required, string $helpText, array $config): int
    {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $maxSort = (new Query())
            ->select(['sortOrder'])
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId])
            ->max('sortOrder') ?? 0;

        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $formId,
            'type' => $type,
            'name' => $handle,
            'required' => $required,
            // Pass the array; Craft's json column encodes it once.
            'config' => $config,
            'sortOrder' => $maxSort + 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $fieldId = (int)$db->getLastInsertID();

        foreach (self::supportedSiteIds($formId) as $siteId) {
            $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
                'fieldId' => $fieldId,
                'siteId' => $siteId,
                'label' => $label,
                'helpText' => $helpText !== '' ? $helpText : null,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        Plugin::getInstance()->getFormStructure()->invalidate($formId);

        return $fieldId;
    }

    /**
     * Update a field. Mirrors FieldsController::actionEdit: structural columns
     * updated once, the per-site label/helpText upserted for the given site.
     *
     * @param array<string, mixed> $config
     */
    public static function update(int $fieldId, int $formId, int $siteId, string $type, string $handle, string $label, bool $required, string $helpText, array $config): void
    {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

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
            'helpText' => $helpText !== '' ? $helpText : null,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ], [
            'label' => $label,
            'helpText' => $helpText !== '' ? $helpText : null,
            'dateUpdated' => $now,
        ])->execute();

        Plugin::getInstance()->getFormStructure()->invalidate($formId);
    }

    /**
     * Delete a field. Mirrors FieldsController::actionDelete (per-site rows
     * cascade via FK).
     */
    public static function delete(int $fieldId, int $formId): void
    {
        $db = Craft::$app->getDb();
        $db->createCommand()->delete('{{%simpleform_fields}}', ['id' => $fieldId])->execute();
        Plugin::getInstance()->getFormStructure()->invalidate($formId);
    }

    /**
     * Reorder a form's fields by an ordered list of field ids. Mirrors
     * FieldsController::actionReorder, invalidating each affected form's cache.
     *
     * @param list<int> $orderedFieldIds
     */
    public static function reorder(array $orderedFieldIds): void
    {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        foreach (array_values($orderedFieldIds) as $index => $fieldId) {
            $db->createCommand()->update('{{%simpleform_fields}}', [
                'sortOrder' => $index + 1,
                'dateUpdated' => $now,
            ], ['id' => $fieldId])->execute();
        }

        $formIds = (new Query())
            ->select(['formId'])
            ->distinct()
            ->from('{{%simpleform_fields}}')
            ->where(['id' => array_values($orderedFieldIds)])
            ->column();

        foreach ($formIds as $formId) {
            Plugin::getInstance()->getFormStructure()->invalidate((int)$formId);
        }
    }

    /**
     * Look up the raw structural row for a field id (or null).
     *
     * @return array<string, mixed>|null
     */
    public static function findField(int $fieldId): ?array
    {
        $row = (new Query())->from('{{%simpleform_fields}}')->where(['id' => $fieldId])->one();
        return is_array($row) ? $row : null;
    }

    /**
     * Site IDs the field should exist on, derived from the form's propagation
     * method. Mirrors FieldsController::supportedSiteIds.
     *
     * @return list<int>
     */
    private static function supportedSiteIds(int $formId): array
    {
        $form = Form::find()->id($formId)->siteId('*')->status(null)->one();
        if (!$form) {
            return [(int)Craft::$app->getSites()->getPrimarySite()->id];
        }

        $ids = $form->supportedSiteIds();

        return $ids !== [] ? $ids : [(int)Craft::$app->getSites()->getPrimarySite()->id];
    }
}

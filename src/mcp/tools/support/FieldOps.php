<?php

namespace anvildev\simpleform\mcp\tools\support;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\FieldSyncService;
use Craft;
use craft\db\Query;

/**
 * MCP-facing field add/edit/reorder/delete for the form-management tools.
 *
 * The structural-row + per-site label/helpText writes and cache invalidation are
 * shared with the CP field builder through {@see \anvildev\simpleform\services\FieldsService},
 * so an agent's field edits hit the exact same tables and cache the CP uses. This
 * class owns only the MCP-specific parts: arg validation, conditional-rule
 * sanitising, and the primary-site fallback when resolving a form's sites.
 *
 * It is an adapter, not new business logic: nothing here is reachable except
 * through a forms:manage-scoped tool.
 */
final class FieldOps
{
    /**
     * Memoized {@see formFieldItems()} results, keyed on `"{formId}:{excludeFieldId}"`.
     *
     * @var array<string, array<int, array{handle:string,label:string,config:array<string,mixed>}>>
     */
    private static array $formFieldItemsCache = [];

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
        // Shared structural rules live on FieldsService; render the {key, params}
        // pairs as raw English for the MCP contract (the CP renders via Craft::t).
        $structured = Plugin::getInstance()->getFields()->validateInput($type, $label, $handle, $config, $formId, $excludeFieldId);
        $errors = [];
        foreach ($structured as $input => $list) {
            foreach ($list as $error) {
                $errors[$input][] = self::interpolate($error['key'], $error['params']);
            }
        }

        // Validate conditional logic against the form's full field set (with this
        // field's candidate state merged in), using the same self-reference /
        // cycle rules as the CP batch save.
        if (is_string($handle) && $handle !== '' && isset($config['conditional'])) {
            $items = self::formFieldItems($formId, $excludeFieldId);
            $items[] = ['handle' => $handle, 'label' => $label ?? $handle, 'config' => $config];
            foreach (FieldSyncService::conditionalSetErrors($items) as $condError) {
                $errors['config'][] = $condError;
            }
        }

        return $errors;
    }

    /**
     * Load the form's existing fields as conditional-validation items
     * ({handle, config}), optionally excluding one field id (the one being edited).
     *
     * @return array<int, array{handle: string, label: string, config: array<string, mixed>}>
     */
    private static function formFieldItems(int $formId, ?int $excludeFieldId): array
    {
        $key = $formId . ':' . ($excludeFieldId ?? '');
        if (isset(self::$formFieldItemsCache[$key])) {
            return self::$formFieldItemsCache[$key];
        }

        $query = (new Query())
            ->select(['id', 'name', 'config'])
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId]);
        if ($excludeFieldId !== null) {
            $query->andWhere(['not', ['id' => $excludeFieldId]]);
        }

        $items = [];
        foreach ($query->all() as $row) {
            $config = $row['config'] ?? null;
            if (is_string($config)) {
                $decoded = json_decode($config, true);
                $config = is_array($decoded) ? $decoded : [];
            }
            $items[] = [
                'handle' => (string)$row['name'],
                'label' => (string)$row['name'],
                'config' => is_array($config) ? $config : [],
            ];
        }

        return self::$formFieldItemsCache[$key] = $items;
    }

    /**
     * Prune conditional rules in a field's config that point at a removed field
     * or at the field itself, keeping persisted MCP-authored rules consistent
     * with the CP path. Returns the cleaned config.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function sanitizeConditional(array $config, int $formId, string $handle, ?int $excludeFieldId): array
    {
        if (!isset($config['conditional'])) {
            return $config;
        }

        $validHandles = [$handle => true];
        foreach (self::formFieldItems($formId, $excludeFieldId) as $item) {
            if ($item['handle'] !== '') {
                $validHandles[$item['handle']] = true;
            }
        }

        return FieldSyncService::sanitizeConditional($config, $validHandles, $handle);
    }

    /**
     * Add a field to a form: prune dangling conditional rules, then hand the
     * write to {@see FieldsService} (one structural row plus a per-site
     * label/helpText row for every site the form supports). Returns the new id.
     *
     * @param array<string, mixed> $config
     * @return int the new field id
     */
    public static function add(int $formId, string $type, string $handle, string $label, bool $required, string $helpText, array $config): int
    {
        $config = self::sanitizeConditional($config, $formId, $handle, null);

        return Plugin::getInstance()->getFields()->add(
            $formId,
            $type,
            $handle,
            $required,
            $config,
            $label,
            $helpText,
            self::supportedSiteIds($formId),
        );
    }

    /**
     * Update a field: prune dangling conditional rules, then hand the write to
     * {@see FieldsService} (structural columns once, the per-site label/helpText
     * upserted for the given site).
     *
     * @param array<string, mixed> $config
     */
    public static function update(int $fieldId, int $formId, int $siteId, string $handle, string $label, bool $required, string $helpText, array $config): void
    {
        $config = self::sanitizeConditional($config, $formId, $handle, $fieldId);

        Plugin::getInstance()->getFields()->update($fieldId, $formId, $siteId, $handle, $required, $config, $label, $helpText);
    }

    /**
     * Delete a field (per-site rows cascade via FK).
     */
    public static function delete(int $fieldId, int $formId): void
    {
        Plugin::getInstance()->getFields()->delete($fieldId, $formId);
    }

    /**
     * Reorder a form's fields by an ordered list of field ids. Mirrors
     * FieldsController::actionReorder, invalidating each affected form's cache.
     *
     * @param list<int> $orderedFieldIds
     */
    public static function reorder(array $orderedFieldIds): void
    {
        // Delegate to the shared FieldsService write path. No form is pinned, so
        // fields are matched by id and every distinct affected form's cache is
        // invalidated — preserving this tool's cross-form-safe behavior.
        Plugin::getInstance()->getFields()->reorder(array_values($orderedFieldIds));
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
     * method, falling back to the primary site. Delegates to the shared
     * {@see \anvildev\simpleform\services\FieldsService::supportedSiteIds()}.
     *
     * @return list<int>
     */
    private static function supportedSiteIds(int $formId): array
    {
        return Plugin::getInstance()->getFields()->supportedSiteIds(
            $formId,
            (int) Craft::$app->getSites()->getPrimarySite()->id,
        );
    }

    /**
     * Interpolate a `{name}` placeholder message with its params — the MCP's
     * raw-English render of the structured rule errors from FieldsService (the
     * CP renders the same `{key, params}` pairs through `Craft::t()`).
     *
     * @param array<string, int|string> $params
     */
    private static function interpolate(string $message, array $params): string
    {
        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{' . $name . '}'] = (string) $value;
        }

        return strtr($message, $replace);
    }
}

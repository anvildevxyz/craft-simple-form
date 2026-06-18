<?php

namespace fabianhaef\simpleform\gql\resolvers;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\Plugin;

/**
 * Transforms a Form element into the array shape consumed by {@see \fabianhaef\simpleform\gql\types\FormType}.
 *
 * Field resolution reuses the same single-source-of-truth field set the CP and
 * Twig rendering use (via FormStructureService → FieldQueryHelper), so the
 * GraphQL schema, the rendered form, and submit validation never drift apart.
 */
final class FormGqlResolver
{
    /**
     * @return array<string, mixed>
     */
    public static function resolve(Form $form): array
    {
        $siteId = (int) $form->siteId;
        $rawFields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id, $siteId);

        return [
            'id' => (int) $form->id,
            'handle' => (string) $form->handle,
            'name' => (string) $form->name,
            'title' => $form->title,
            'description' => $form->description,
            'siteId' => $siteId,
            'fields' => array_map([self::class, 'mapField'], $rawFields),
            'integrations' => self::mapIntegrations((int) $form->id),
        ];
    }

    /**
     * Expose a form's integrations read-only — name/type/enabled only. Settings
     * (which may hold secrets) are deliberately never included.
     *
     * @return list<array{name: string, type: string, enabled: bool}>
     */
    private static function mapIntegrations(int $formId): array
    {
        $result = [];
        foreach (Plugin::getInstance()->getIntegrations()->getIntegrationsForForm($formId) as $integration) {
            $result[] = [
                'name' => $integration->name,
                'type' => $integration->type,
                'enabled' => $integration->enabled,
            ];
        }
        return $result;
    }

    /**
     * Map a resolved field row (see {@see \fabianhaef\simpleform\helpers\FieldQueryHelper})
     * to the GraphQL field shape.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapField(array $row): array
    {
        $config = is_array($row['config'] ?? null) ? $row['config'] : [];
        // Overlay this site's option-label translations so the GraphQL schema
        // matches the rendered form for the requested site (option values stay
        // canonical; missing translations fall back to the source label).
        $config = FieldQueryHelper::applyOptionLabels(
            $config,
            is_array($row['optionLabels'] ?? null) ? $row['optionLabels'] : []
        );
        $required = (bool) ($row['required'] ?? ($config['required'] ?? false));

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'label' => (string) ($row['label'] ?? $row['name']),
            'helpText' => ($row['helpText'] ?? '') !== '' ? $row['helpText'] : null,
            'required' => $required,
            'sortOrder' => isset($row['sortOrder']) ? (int) $row['sortOrder'] : null,
            'page' => self::pageOf($config),
            'placeholder' => self::stringOrNull($config['placeholder'] ?? null),
            'options' => self::mapOptions($config['options'] ?? null),
            'validation' => self::mapValidation($config, $required),
            'conditional' => self::mapConditional($config['conditional'] ?? null),
        ];
    }

    /**
     * Map the stored conditional block to the GraphQL shape, or null when the
     * field has no enabled conditional logic.
     *
     * @param mixed $conditional
     * @return array<string, mixed>|null
     */
    private static function mapConditional(mixed $conditional): ?array
    {
        if (!is_array($conditional) || empty($conditional['enabled'])) {
            return null;
        }

        $required = is_array($conditional['required'] ?? null) ? $conditional['required'] : null;
        $hasRequired = $required !== null && !empty($required['enabled']);

        return [
            'action' => ($conditional['action'] ?? 'show') === 'hide' ? 'hide' : 'show',
            'match' => ($conditional['match'] ?? 'all') === 'any' ? 'any' : 'all',
            'rules' => self::mapRules($conditional['rules'] ?? null),
            'requiredMatch' => $hasRequired ? (($required['match'] ?? 'all') === 'any' ? 'any' : 'all') : null,
            'requiredRules' => $hasRequired ? self::mapRules($required['rules'] ?? null) : [],
        ];
    }

    /**
     * @param mixed $rules
     * @return list<array{field: string, operator: string, value: string|null}>
     */
    private static function mapRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            return [];
        }

        $result = [];
        foreach ($rules as $rule) {
            if (is_array($rule) && isset($rule['field'])) {
                $result[] = [
                    'field' => (string) $rule['field'],
                    'operator' => (string) ($rule['operator'] ?? 'eq'),
                    'value' => isset($rule['value']) ? (string) $rule['value'] : null,
                ];
            }
        }

        return $result;
    }

    /**
     * Normalize the stored options (array of {label, value}) to the GraphQL shape.
     *
     * @param mixed $options
     * @return list<array{label: string, value: string}>
     */
    private static function mapOptions(mixed $options): array
    {
        if (is_string($options)) {
            $decoded = json_decode($options, true);
            $options = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($options)) {
            return [];
        }

        $result = [];
        foreach ($options as $opt) {
            if (is_array($opt) && isset($opt['value'], $opt['label'])) {
                $result[] = ['label' => (string) $opt['label'], 'value' => (string) $opt['value']];
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function mapValidation(array $config, bool $required): array
    {
        return [
            'required' => $required,
            'minLength' => self::intOrNull($config['minLength'] ?? null),
            'maxLength' => self::intOrNull($config['maxLength'] ?? null),
            'min' => self::floatOrNull($config['min'] ?? null),
            'max' => self::floatOrNull($config['max'] ?? null),
            'pattern' => self::stringOrNull($config['pattern'] ?? null),
        ];
    }

    /**
     * The 1-based step/page for a field (defaults to 1).
     *
     * @param array<string, mixed> $config
     */
    private static function pageOf(array $config): int
    {
        $page = $config['page'] ?? 1;
        return (is_numeric($page) && (int) $page >= 1) ? (int) $page : 1;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return (is_string($value) && $value !== '') ? $value : null;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && is_numeric($value))) ? (int) $value : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) ? (float) $value : null;
    }
}

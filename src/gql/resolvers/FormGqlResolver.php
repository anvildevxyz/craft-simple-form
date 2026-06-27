<?php

namespace anvildev\simpleform\gql\resolvers;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\fields\ElementRelationFieldType;
use anvildev\simpleform\fields\OpinionScaleFieldType;
use anvildev\simpleform\fields\RatingFieldType;
use anvildev\simpleform\helpers\ConditionalEvaluator;
use anvildev\simpleform\helpers\FieldQueryHelper;
use anvildev\simpleform\Plugin;
use craft\base\ElementInterface;

/**
 * Transforms a Form element into the array shape consumed by {@see \anvildev\simpleform\gql\types\FormType}.
 *
 * Field resolution reuses the same single-source-of-truth field set the CP and
 * Twig rendering use (via FormStructureService → FieldQueryHelper), so the
 * GraphQL schema, the rendered form, and submit validation never drift apart.
 *
 * @phpstan-import-type ResolvedFieldRow from FieldQueryHelper
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
            'fields' => array_map(static fn(array $row): array => self::mapField($row), $rawFields),
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
     * Map a resolved field row (see {@see \anvildev\simpleform\helpers\FieldQueryHelper})
     * to the GraphQL field shape.
     *
     * @param ResolvedFieldRow $row
     * @return array<string, mixed>
     */
    private static function mapField(array $row): array
    {
        $config = $row['config'];
        // Overlay this site's option-label translations so the GraphQL schema
        // matches the rendered form for the requested site (option values stay
        // canonical; missing translations fall back to the source label).
        $config = FieldQueryHelper::applyOptionLabels(
            $config,
            is_array($row['optionLabels'] ?? null) ? $row['optionLabels'] : []
        );
        $required = $row['required'];

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'label' => (string) $row['label'],
            'helpText' => ($row['helpText'] ?? '') !== '' ? $row['helpText'] : null,
            'required' => $required,
            'sortOrder' => (int) $row['sortOrder'],
            'page' => self::pageOf($config),
            'placeholder' => self::stringOrNull($config['placeholder'] ?? null),
            'options' => self::mapOptions($config['options'] ?? null),
            'validation' => self::mapValidation($config, $required, (string) $row['type']),
            'conditional' => self::mapConditional($config['conditional'] ?? null),
            'relation' => self::mapRelation((string) $row['type'], $config),
        ];
    }

    /**
     * Map a relation field's element-relation config (element type, allowed
     * sources, single/multi, limit, and the resolved option list) for the GraphQL
     * schema, or null for any non-relation field type.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>|null
     */
    private static function mapRelation(string $type, array $config): ?array
    {
        $field = Plugin::getInstance()->getFieldTypeRegistry()->getFieldType($type, $config);
        if (!$field instanceof ElementRelationFieldType) {
            return null;
        }

        // Options resolve for the current site so titles match the requested
        // form's language; the allowed set already excludes disabled/other-site
        // elements (see ElementRelationFieldType::optionList()).
        $options = [];
        /** @var array<int, ElementInterface> $elements */
        $elements = $field->allowedElementQuery()->indexBy('id')->all();
        foreach ($elements as $id => $element) {
            $options[] = ['id' => (int) $id, 'title' => (string) $element];
        }

        return [
            'elementType' => $type,
            'sources' => $field->sources(),
            'multiple' => $field->isMultiple(),
            'limit' => $field->limit(),
            'options' => $options,
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
            'action' => ($conditional['action'] ?? ConditionalEvaluator::ACTION_SHOW) === ConditionalEvaluator::ACTION_HIDE ? ConditionalEvaluator::ACTION_HIDE : ConditionalEvaluator::ACTION_SHOW,
            'match' => ConditionalEvaluator::normalizeMatch($conditional['match'] ?? null),
            'rules' => self::mapRules($conditional['rules'] ?? null),
            'requiredMatch' => $hasRequired ? ConditionalEvaluator::normalizeMatch($required['match'] ?? null) : null,
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
    private static function mapValidation(array $config, bool $required, string $type): array
    {
        $validation = [
            'required' => $required,
            'minLength' => self::intOrNull($config['minLength'] ?? null),
            'maxLength' => self::intOrNull($config['maxLength'] ?? null),
            'min' => self::floatOrNull($config['min'] ?? null),
            'max' => self::floatOrNull($config['max'] ?? null),
            'pattern' => self::stringOrNull($config['pattern'] ?? null),
            'iconStyle' => null,
            'leftLabel' => null,
            'rightLabel' => null,
        ];

        // For the scale field types, expose the effective (clamped) bounds plus
        // the render hints a headless client needs, sourced from the field type
        // itself so the schema matches what the server validates.
        if ($type === RatingFieldType::getType()) {
            $field = new RatingFieldType($config);
            $validation['min'] = 1.0;
            $validation['max'] = (float) $field->max();
            $validation['iconStyle'] = $field->iconStyle();
        } elseif ($type === OpinionScaleFieldType::getType()) {
            $field = new OpinionScaleFieldType($config);
            $validation['min'] = (float) $field->min();
            $validation['max'] = (float) $field->max();
            $validation['leftLabel'] = self::stringOrNull($field->leftLabel());
            $validation['rightLabel'] = self::stringOrNull($field->rightLabel());
        }

        return $validation;
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

<?php

namespace fabianhaef\simpleform\gql\resolvers;

use fabianhaef\simpleform\elements\Form;
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
        ];
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
        $required = (bool) ($row['required'] ?? ($config['required'] ?? false));

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'label' => (string) ($row['label'] ?? $row['name']),
            'helpText' => ($row['helpText'] ?? '') !== '' ? $row['helpText'] : null,
            'required' => $required,
            'sortOrder' => isset($row['sortOrder']) ? (int) $row['sortOrder'] : null,
            'placeholder' => self::stringOrNull($config['placeholder'] ?? null),
            'options' => self::mapOptions($config['options'] ?? null),
            'validation' => self::mapValidation($config, $required),
        ];
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

<?php

namespace anvildev\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\simpleform\gql\types\SimpleFormObjectType */
class FieldValidationType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormFieldValidation';
    }

    protected static function getDescription(): string
    {
        return 'The validation rules a headless client should mirror for a field. '
            . 'Server-side validation is always authoritative; these are the same '
            . 'constraints the field type enforces on submit.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'required' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether a non-empty value must be supplied.',
            ],
            'minLength' => [
                'type' => Type::int(),
                'description' => 'Minimum string length, when the field type enforces one.',
            ],
            'maxLength' => [
                'type' => Type::int(),
                'description' => 'Maximum string length, when the field type enforces one.',
            ],
            'min' => [
                'type' => Type::float(),
                'description' => 'Minimum numeric value, for number fields.',
            ],
            'max' => [
                'type' => Type::float(),
                'description' => 'Maximum numeric value, for number fields.',
            ],
            'pattern' => [
                'type' => Type::string(),
                'description' => 'A regular expression the value must match, when configured.',
            ],
            'iconStyle' => [
                'type' => Type::string(),
                'description' => 'For rating fields: the icon preset (star, heart, number).',
            ],
            'leftLabel' => [
                'type' => Type::string(),
                'description' => 'For opinion-scale fields: the left anchor label (e.g. "Not likely").',
            ],
            'rightLabel' => [
                'type' => Type::string(),
                'description' => 'For opinion-scale fields: the right anchor label (e.g. "Very likely").',
            ],
        ];
    }
}

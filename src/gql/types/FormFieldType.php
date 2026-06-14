<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType */
class FormFieldType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormField';
    }

    protected static function getDescription(): string
    {
        return 'A single field in a Simple Form schema. All field variants share '
            . 'this shape; the `type` discriminator (`text`, `email`, `textarea`, '
            . '`select`, `checkbox`, `radio`, `date`, `number`) tells a client how '
            . 'to render it, and `options`/`validation` carry the variant-specific '
            . 'detail.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The field id. Submit values under `field_<id>`.',
            ],
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The field handle (site-agnostic).',
            ],
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The field-type discriminator: one of text, email, '
                    . 'textarea, select, checkbox, radio, date, number.',
            ],
            'label' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The field label for the requested site (falls back '
                    . 'to the handle when untranslated).',
            ],
            'helpText' => [
                'type' => Type::string(),
                'description' => 'Optional per-site help text.',
            ],
            'required' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the field is required.',
            ],
            'sortOrder' => [
                'type' => Type::int(),
                'description' => 'Display order within the form (ascending).',
            ],
            'placeholder' => [
                'type' => Type::string(),
                'description' => 'Placeholder text, when configured.',
            ],
            'options' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(FieldOptionType::getType()))),
                'description' => 'Selectable options for choice fields (select, radio, '
                    . 'checkbox); an empty list for other field types.',
            ],
            'validation' => [
                'type' => Type::nonNull(FieldValidationType::getType()),
                'description' => 'The validation rules the server enforces on submit.',
            ],
        ];
    }
}

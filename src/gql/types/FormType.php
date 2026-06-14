<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType */
class FormType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleForm';
    }

    protected static function getDescription(): string
    {
        return 'A Simple Form definition (schema only). Exposes the form metadata '
            . 'and its resolved field set for the requested site. Submission data '
            . 'is never exposed here.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => ['type' => Type::nonNull(Type::int())],
            'handle' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The form handle (globally unique, site-agnostic).',
            ],
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The internal form name (site-agnostic).',
            ],
            'title' => [
                'type' => Type::string(),
                'description' => 'The public title for the requested site.',
            ],
            'description' => [
                'type' => Type::string(),
                'description' => 'The public description for the requested site.',
            ],
            'siteId' => ['type' => Type::nonNull(Type::int())],
            'fields' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(FormFieldType::getType()))),
                'description' => 'The form\'s fields in display order.',
            ],
        ];
    }
}

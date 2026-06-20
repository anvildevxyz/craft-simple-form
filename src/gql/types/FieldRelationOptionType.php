<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/**
 * A single selectable element in a relation field: the element id (submit this)
 * and its title (display this).
 *
 * @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FieldRelationOptionType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormFieldRelationOption';
    }

    protected static function getDescription(): string
    {
        return 'A selectable element in a relation field: its id (the submitted '
            . 'value) and its title (the display label).';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => [
                'type' => Type::nonNull(Type::int()),
                'description' => 'The element id — submit this as the field value.',
            ],
            'title' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The element title for the requested site.',
            ],
        ];
    }
}

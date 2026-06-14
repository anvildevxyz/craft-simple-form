<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType */
class FieldOptionType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormFieldOption';
    }

    protected static function getDescription(): string
    {
        return 'A selectable option for a choice field (select, radio, checkbox).';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'label' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The human-readable option label.',
            ],
            'value' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The value submitted when this option is chosen.',
            ],
        ];
    }
}

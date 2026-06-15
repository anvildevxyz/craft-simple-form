<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType */
class ConditionalRuleType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormConditionalRule';
    }

    protected static function getDescription(): string
    {
        return 'A single conditional rule: the target field handle, a comparison '
            . 'operator, and the value to compare against the target field\'s '
            . 'submitted value.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'field' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Handle of the field this rule is evaluated against.',
            ],
            'operator' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Comparison operator: one of eq, neq, empty, '
                    . 'notEmpty, contains, gt, lt.',
            ],
            'value' => [
                'type' => Type::string(),
                'description' => 'The value compared against the target field\'s value '
                    . '(ignored for the empty / notEmpty operators).',
            ],
        ];
    }
}

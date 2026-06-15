<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType */
class FieldConditionalType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormFieldConditional';
    }

    protected static function getDescription(): string
    {
        return 'Conditional logic for a field. `action` + `match` + `rules` control '
            . 'visibility (show/hide the field when all/any rules match); the '
            . 'optional `requiredMatch` + `requiredRules` make the field required '
            . 'when those rules match. Server enforcement is authoritative — a '
            . 'hidden field is neither validated nor stored.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'action' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'Visibility behaviour: "show" (hidden until rules match) '
                    . 'or "hide" (visible until rules match).',
            ],
            'match' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'How visibility rules combine: "all" (AND) or "any" (OR).',
            ],
            'rules' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(ConditionalRuleType::getType()))),
                'description' => 'The visibility rules.',
            ],
            'requiredMatch' => [
                'type' => Type::string(),
                'description' => 'How conditional-required rules combine ("all"/"any"), '
                    . 'or null when the field has no conditional-required logic.',
            ],
            'requiredRules' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(ConditionalRuleType::getType()))),
                'description' => 'Rules that, when matched, make the field required '
                    . '(empty when there is no conditional-required logic).',
            ],
        ];
    }
}

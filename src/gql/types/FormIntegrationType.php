<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType */
class FormIntegrationType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormIntegration';
    }

    protected static function getDescription(): string
    {
        return 'An outbound integration configured on a form. Exposes only its '
            . 'name, type, and enabled state — never its settings or secrets.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'name' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The integration\'s display name.',
            ],
            'type' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The integration type handle (e.g. "webhook").',
            ],
            'enabled' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether this integration is dispatched on submission.',
            ],
        ];
    }
}

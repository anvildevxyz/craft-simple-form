<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType */
class SubmissionErrorType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormSubmissionError';
    }

    protected static function getDescription(): string
    {
        return 'A single validation/processing error for a submit attempt.';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'key' => [
                'type' => Type::nonNull(Type::string()),
                'description' => 'The error key: `field_<id>` for a field error, or a '
                    . 'general key such as `form`, `captcha`, or `submission`.',
            ],
            'messages' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                'description' => 'One or more human-readable messages for this key.',
            ],
        ];
    }
}

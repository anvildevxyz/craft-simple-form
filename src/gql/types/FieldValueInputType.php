<?php

namespace fabianhaef\simpleform\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * Input object for one submitted field value. Submission payloads are a list of
 * these, which keeps the mutation schema fixed even though a form's fields are
 * dynamic.
 */
class FieldValueInputType
{
    public static function getName(): string
    {
        return 'SimpleFormFieldValueInput';
    }

    public static function getType(): InputObjectType
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new InputObjectType([
            'name' => self::getName(),
            'description' => 'One submitted field value.',
            'fields' => [
                'fieldId' => [
                    'type' => Type::nonNull(Type::int()),
                    'description' => 'The id of the field this value is for (see SimpleFormField.id).',
                ],
                'value' => [
                    'type' => Type::string(),
                    'description' => 'The submitted value. For multi-value fields, join with the field\'s expected delimiter.',
                ],
                'values' => [
                    'type' => Type::listOf(Type::nonNull(Type::string())),
                    'description' => 'Multiple submitted values, for checkbox-style fields. Takes precedence over `value` when set.',
                ],
            ],
        ]));
    }
}

<?php

namespace fabianhaef\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \fabianhaef\simpleform\gql\types\SimpleFormObjectType */
class SubmitFormPayloadType extends SimpleFormObjectType
{
    public static function getName(): string
    {
        return 'SimpleFormSubmitPayload';
    }

    protected static function getDescription(): string
    {
        return 'The result of a submitForm mutation. On success `success` is true '
            . 'and `submissionId` is set; on validation failure `success` is false '
            . 'and `errors` lists the problems (no submission is stored).';
    }

    /** @return GqlFieldDefinitionMap */
    public static function getFieldDefinitions(): array
    {
        return [
            'success' => [
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the submission was accepted and stored.',
            ],
            'submissionId' => [
                'type' => Type::int(),
                'description' => 'The id of the stored submission, or null when not stored.',
            ],
            'errors' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SubmissionErrorType::getType()))),
                'description' => 'Validation/processing errors; empty on success.',
            ],
        ];
    }
}

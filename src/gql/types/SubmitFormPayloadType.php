<?php

namespace anvildev\simpleform\gql\types;

use GraphQL\Type\Definition\Type;

/** @phpstan-import-type GqlFieldDefinitionMap from \anvildev\simpleform\gql\types\SimpleFormObjectType */
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
            'redirectUrl' => [
                'type' => Type::string(),
                'description' => 'The resolved post-submit redirect URL on success (per the form\'s '
                    . 'post-submit action), or null when the form shows an inline message.',
            ],
            'message' => [
                'type' => Type::string(),
                'description' => 'The resolved post-submit confirmation message on success (per the form\'s '
                    . 'submit message, falling back to the global setting), or null otherwise. Matches the '
                    . '`message` the front-end AJAX SubmitController response returns.',
            ],
            'quizScore' => [
                'type' => Type::int(),
                'description' => 'Raw quiz score on a quiz form, or null when the form is not a quiz.',
            ],
            'quizMaxScore' => [
                'type' => Type::int(),
                'description' => 'Maximum attainable quiz score, or null when the form is not a quiz.',
            ],
            'quizPercentage' => [
                'type' => Type::int(),
                'description' => 'Quiz score as a percentage (0–100), or null when not a quiz / no answer key.',
            ],
            'quizGrade' => [
                'type' => Type::string(),
                'description' => 'Quiz grade band label, or null when not a quiz / no bands configured.',
            ],
            'errors' => [
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SubmissionErrorType::getType()))),
                'description' => 'Validation/processing errors; empty on success.',
            ],
        ];
    }
}

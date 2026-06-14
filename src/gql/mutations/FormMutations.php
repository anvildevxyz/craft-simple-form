<?php

namespace fabianhaef\simpleform\gql\mutations;

use Craft;
use craft\gql\base\Mutation as BaseMutation;
use craft\helpers\Gql as GqlHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\gql\types\FieldValueInputType;
use fabianhaef\simpleform\gql\types\SubmitFormPayloadType;
use fabianhaef\simpleform\Plugin;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL mutation for submitting a form.
 *
 * Routes through {@see \fabianhaef\simpleform\services\SubmissionService::submit()}
 * — the exact same entry point the front-end SubmitController uses — so
 * validation, spam protection, the before/after events, and the notification
 * email all run identically to the Twig path.
 *
 * Gated by the `simpleFormSubmissions:create` schema component.
 *
 * Spam policy for the headless context:
 *  - The honeypot is always honored (a `honeypot` arg, transport-agnostic) —
 *    a non-empty value is silently dropped.
 *  - Captcha is skipped for the GraphQL token because a server-side caller
 *    cannot produce a browser-issued reCAPTCHA token; the schema-component
 *    scope is what authorizes the channel, and the honeypot still applies, so
 *    the mutation is not a blanket spam bypass.
 */
class FormMutations extends BaseMutation
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getMutations(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canSchema('simpleFormSubmissions', 'create')) {
            return [];
        }

        return [
            'submitForm' => [
                'type' => Type::nonNull(SubmitFormPayloadType::getType()),
                'args' => [
                    'handle' => ['type' => Type::string(), 'description' => 'The handle of the form to submit to.'],
                    'id' => ['type' => Type::int(), 'description' => 'The id of the form to submit to (alternative to handle).'],
                    'siteId' => ['type' => Type::int(), 'description' => 'Site to submit against. Defaults to the form\'s/primary site.'],
                    'values' => [
                        'type' => Type::nonNull(Type::listOf(Type::nonNull(FieldValueInputType::getType()))),
                        'description' => 'The submitted field values.',
                    ],
                    'honeypot' => [
                        'type' => Type::string(),
                        'description' => 'Honeypot value; leave empty. A non-empty value is treated as spam and silently dropped.',
                    ],
                ],
                'description' => 'Submits a form. Returns a payload with success/errors; '
                    . 'invalid input is reported via the errors list, not a hard failure.',
                'resolve' => [self::class, 'resolveSubmit'],
            ],
        ];
    }

    /**
     * @param mixed $source
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveSubmit(mixed $source, array $args): array
    {
        $siteId = isset($args['siteId']) && (int) $args['siteId'] > 0
            ? (int) $args['siteId']
            : (int) Craft::$app->getSites()->getPrimarySite()->id;

        $query = Form::find()->siteId($siteId);
        if (isset($args['id'])) {
            $query->id((int) $args['id']);
        } elseif (isset($args['handle']) && $args['handle'] !== '') {
            $query->handle((string) $args['handle']);
        } else {
            return self::errorPayload([['key' => 'form', 'messages' => ['A form handle or id is required.']]]);
        }

        $form = $query->one();
        if (!$form instanceof Form) {
            return self::errorPayload([['key' => 'form', 'messages' => ['Form not found.']]]);
        }

        // Build the field-id => value map from the input list.
        $values = [];
        $inputValues = is_array($args['values'] ?? null) ? $args['values'] : [];
        foreach ($inputValues as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $fieldId = (int) ($entry['fieldId'] ?? 0);
            if ($fieldId <= 0) {
                continue;
            }
            if (isset($entry['values']) && is_array($entry['values'])) {
                $values[$fieldId] = $entry['values'];
            } else {
                $values[$fieldId] = $entry['value'] ?? null;
            }
        }

        $userId = Craft::$app->getUser()->getId();

        $result = Plugin::getInstance()->getSubmissionService()->submit($form, $values, [
            'honeypot' => (string) ($args['honeypot'] ?? ''),
            // A scoped GraphQL token authorizes this channel; a server-side caller
            // can't carry a browser reCAPTCHA token, so skip captcha but keep the
            // honeypot enforced above.
            'skipCaptcha' => true,
            'siteId' => $siteId,
            'userId' => $userId !== null ? (int) $userId : null,
        ]);

        // Honeypot hit: silently dropped (no submission, no errors). Report a
        // generic success so a bot gets no signal.
        if ($result['submission'] === null && $result['errors'] === null) {
            return [
                'success' => true,
                'submissionId' => null,
                'errors' => [],
            ];
        }

        if (!empty($result['errors'])) {
            return self::errorPayload(self::formatErrors($result['errors']));
        }

        return [
            'success' => true,
            'submissionId' => $result['submission']?->id !== null ? (int) $result['submission']->id : null,
            'errors' => [],
        ];
    }

    /**
     * @param array<string, mixed> $errors error map: key => list<string>|string
     * @return list<array{key: string, messages: list<string>}>
     */
    private static function formatErrors(array $errors): array
    {
        $formatted = [];
        foreach ($errors as $key => $messages) {
            $formatted[] = [
                'key' => (string) $key,
                'messages' => array_values(array_map('strval', (array) $messages)),
            ];
        }
        return $formatted;
    }

    /**
     * @param list<array{key: string, messages: list<string>}> $errors
     * @return array<string, mixed>
     */
    private static function errorPayload(array $errors): array
    {
        return [
            'success' => false,
            'submissionId' => null,
            'errors' => $errors,
        ];
    }
}

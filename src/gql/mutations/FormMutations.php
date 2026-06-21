<?php

namespace fabianhaef\simpleform\gql\mutations;

use Craft;
use craft\gql\base\Mutation as BaseMutation;
use craft\helpers\Gql as GqlHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
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
 *  - Captcha is enforced by default (F8): when captcha is enabled the caller
 *    must pass a `captchaToken`. The bypass is granted only when the operator
 *    sets `allowGraphqlCaptchaBypass` for trusted server-to-server callers, so
 *    a leaked GraphQL token is not a blanket spam bypass.
 */
class FormMutations extends BaseMutation
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getMutations(bool $checkToken = true): array
    {
        $mutations = [];

        if (!$checkToken || GqlHelper::canSchema('simpleFormSubmissions', 'edit')) {
            $mutations['updateSubmission'] = [
                'type' => Type::nonNull(SubmitFormPayloadType::getType()),
                'args' => [
                    'id' => ['type' => Type::nonNull(Type::int()), 'description' => 'The id of the submission to edit.'],
                    'token' => [
                        'type' => Type::string(),
                        'description' => 'The secure edit token for the submission. Required unless the request '
                            . 'is an authenticated user who owns the submission.',
                    ],
                    'values' => [
                        'type' => Type::nonNull(Type::listOf(Type::nonNull(FieldValueInputType::getType()))),
                        'description' => 'The edited field values.',
                    ],
                    'honeypot' => [
                        'type' => Type::string(),
                        'description' => 'Honeypot value; leave empty. A non-empty value is treated as spam and silently dropped.',
                    ],
                    'captchaToken' => [
                        'type' => Type::string(),
                        'description' => 'Captcha token to verify when captcha is enabled, unless the operator '
                            . 'has enabled the GraphQL captcha bypass.',
                    ],
                ],
                'description' => 'Edits an existing submission, re-validated through the same path as a create. '
                    . 'Authorized by a valid edit token or by an authenticated owner; gated by the '
                    . 'simpleFormSubmissions:edit schema component.',
                'resolve' => [self::class, 'resolveUpdate'],
            ];
        }

        if ($checkToken && !GqlHelper::canSchema('simpleFormSubmissions', 'create')) {
            return $mutations;
        }

        $mutations['submitForm'] = [
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
                'captchaToken' => [
                    'type' => Type::string(),
                    'description' => 'Captcha token to verify when captcha is enabled. Required for headless '
                        . 'clients unless the operator has enabled the GraphQL captcha bypass.',
                ],
            ],
            'description' => 'Submits a form. Returns a payload with success/errors; '
                . 'invalid input is reported via the errors list, not a hard failure.',
            'resolve' => [self::class, 'resolveSubmit'],
        ];

        return $mutations;
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

        // Abuse throttle, shared with the front-end submit path so GraphQL can't
        // be used to sidestep the per-IP limit (audit follow-up).
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        if (Plugin::getInstance()->getSubmissionService()->isRateLimited($request->getUserIP())) {
            return self::errorPayload([['key' => 'form', 'messages' => ['Too many submissions. Please wait a moment and try again.']]]);
        }

        // Build the field-id => value map from the input list.
        $values = self::buildValueMap($args['values'] ?? null);

        $userId = Craft::$app->getUser()->getId();

        // F8: captcha is enforced for GraphQL by default. Headless clients pass a
        // `captchaToken`; the bypass is only granted when the operator has
        // explicitly opted in for trusted server-to-server callers. The honeypot
        // is always enforced regardless.
        $captchaToken = isset($args['captchaToken']) ? (string) $args['captchaToken'] : null;
        $skipCaptcha = Plugin::getInstance()->getSettings()->allowGraphqlCaptchaBypass;

        $result = Plugin::getInstance()->getSubmissionService()->submit($form, $values, [
            'honeypot' => (string) ($args['honeypot'] ?? ''),
            'captchaToken' => $captchaToken,
            'skipCaptcha' => $skipCaptcha,
            'siteId' => $siteId,
            'userId' => $userId !== null ? (int) $userId : null,
        ]);

        // Honeypot hit: silently dropped (no submission, no errors). Report a
        // generic success so a bot gets no signal.
        if ($result['submission'] === null && $result['errors'] === null) {
            return [
                'success' => true,
                'submissionId' => null,
                'redirectUrl' => null,
                'errors' => [],
            ];
        }

        if (!empty($result['errors'])) {
            return self::errorPayload(self::formatErrors($result['errors']));
        }

        // Resolve the per-form post-submit behavior so headless clients receive
        // the same templated redirect the front-end JS would follow.
        $post = Plugin::getInstance()->getSubmissionService()->resolvePostSubmit(
            $form,
            $result['submission'],
            $result['data'] ?? [],
        );

        return [
            'success' => true,
            'submissionId' => $result['submission']?->id !== null ? (int) $result['submission']->id : null,
            'redirectUrl' => $post['redirectUrl'],
            'errors' => [],
        ];
    }

    /**
     * Resolve the `updateSubmission` mutation (#144). Re-checks token/owner
     * authorization and routes the edit through the same shared save core as a
     * create, so validation, conditional logic, and spam protection behave
     * identically. Never leaks the token or secret in the payload.
     *
     * @param mixed $source
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public static function resolveUpdate(mixed $source, array $args): array
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return self::errorPayload([['key' => 'submission', 'messages' => ['A submission id is required.']]]);
        }

        $submission = Submission::find()->id($id)->siteId('*')->one();
        if (!$submission instanceof Submission) {
            return self::errorPayload([['key' => 'submission', 'messages' => ['Submission not found.']]]);
        }

        // Shared per-IP abuse throttle (also guards the create path).
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        if (Plugin::getInstance()->getSubmissionService()->isRateLimited($request->getUserIP())) {
            return self::errorPayload([['key' => 'form', 'messages' => ['Too many submissions. Please wait a moment and try again.']]]);
        }

        // Authorize: a valid token, or an authenticated owner — plus allowEditing
        // and the edit window, all enforced server-side.
        $token = isset($args['token']) ? (string) $args['token'] : null;
        $userId = Craft::$app->getUser()->getId();
        $actor = Plugin::getInstance()->getSubmissionService()->authorizeEdit(
            $submission,
            $token,
            $userId !== null ? (int) $userId : null,
        );
        if ($actor === null) {
            return self::errorPayload([['key' => 'auth', 'messages' => ['You are not authorized to edit this submission.']]]);
        }

        // Build the field-id => value map from the input list.
        $values = self::buildValueMap($args['values'] ?? null);

        $captchaToken = isset($args['captchaToken']) ? (string) $args['captchaToken'] : null;
        $skipCaptcha = Plugin::getInstance()->getSettings()->allowGraphqlCaptchaBypass;

        $result = Plugin::getInstance()->getSubmissionService()->update($submission, $values, [
            'honeypot' => (string) ($args['honeypot'] ?? ''),
            'captchaToken' => $captchaToken,
            'skipCaptcha' => $skipCaptcha,
            'actor' => $actor,
        ]);

        if ($result['submission'] === null && $result['errors'] === null) {
            return ['success' => true, 'submissionId' => null, 'errors' => []];
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
     * Build the field-id => value map from the GraphQL `values` input list.
     * Entries without a positive `fieldId` are skipped; a present `values`
     * array is preferred over the scalar `value`. Shared by the submit and
     * update resolvers so both interpret the input identically.
     *
     * @param mixed $inputValues the raw `values` argument
     * @return array<int, mixed>
     */
    private static function buildValueMap(mixed $inputValues): array
    {
        $values = [];
        foreach (is_array($inputValues) ? $inputValues : [] as $entry) {
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
        return $values;
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
            'redirectUrl' => null,
            'errors' => $errors,
        ];
    }
}

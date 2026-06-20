<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\models\GqlSchema;
use craft\test\TestMailer;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\CaptchaService;
use yii\mail\MessageInterface;

/** Deterministic captcha for F8 tests: only "good-token" verifies. */
class StubCaptchaService extends CaptchaService
{
    public function verify(?string $token = null): bool
    {
        return $token === 'good-token';
    }
}

/**
 * End-to-end coverage of the GraphQL surface: querying a form's schema, the
 * submitForm mutation (success + validation failure), and schema-component
 * (scope) gating.
 *
 * Queries are executed the same way Craft executes a real headless request:
 * a GqlSchema with the relevant scope is set active, then
 * `Craft::$app->getGql()->executeQuery()` runs the document end-to-end through
 * the registered types/queries/mutations and resolvers.
 *
 * @group requires-craft
 */
class GraphQlTest extends SimpleFormTestCase
{
    /**
     * Build + activate a GraphQL schema with the given scope components, run a
     * document, and return the decoded result.
     *
     * @param list<string> $scope
     * @param array<string, mixed>|null $variables
     * @return array<string, mixed>
     */
    private function execute(string $document, array $scope, ?array $variables = null): array
    {
        $schema = new GqlSchema([
            'id' => 1,
            'uid' => 'test-schema-uid',
            'name' => 'Test Schema',
            'scope' => $scope,
        ]);

        $gql = Craft::$app->getGql();
        // The schema definition (and which scope-gated queries/mutations it
        // contains) is cached after the first build. Flush so each execution
        // rebuilds against the scope under test — otherwise a permissive earlier
        // test would leak its fields into a later, more-restricted one.
        $gql->flushCaches();
        $gql->setActiveSchema($schema);

        return $gql->executeQuery($schema, $document, $variables);
    }

    /**
     * @return list<MessageInterface>
     */
    private function captureSentMessages(callable $work): array
    {
        $mailer = Craft::$app->getMailer();
        $collected = [];

        if ($mailer instanceof TestMailer) {
            $original = $mailer->callback;
            $mailer->callback = function (MessageInterface $message) use (&$collected, $original): void {
                $collected[] = $message;
                if (is_callable($original)) {
                    $original($message);
                }
            };
            try {
                $work();
            } finally {
                $mailer->callback = $original;
            }
        } else {
            $work();
        }

        return $collected;
    }

    public function testQueryReturnsFormSchema(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm('Contact', 'gqlContactForm', 'Contact', $siteId);
        $nameId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true, ['placeholder' => 'Your name']);
        $colorId = $this->createField($form->id, 'select', 'color', 'Favourite Colour', false, [
            'options' => [
                ['label' => 'Red', 'value' => 'red'],
                ['label' => 'Blue', 'value' => 'blue'],
            ],
        ]);

        $document = <<<'GQL'
        query ($handle: String!, $siteId: Int) {
            simpleForm(handle: $handle, siteId: $siteId) {
                id
                handle
                name
                title
                fields {
                    id
                    name
                    type
                    label
                    required
                    placeholder
                    options { label value }
                    validation { required }
                }
            }
        }
        GQL;

        $result = $this->execute($document, ['simpleForms:read'], [
            'handle' => 'gqlContactForm',
            'siteId' => $siteId,
        ]);

        $this->assertArrayNotHasKey('errors', $result, 'Query should not error: ' . json_encode($result['errors'] ?? null));

        $data = $result['data']['simpleForm'];
        $this->assertSame((int) $form->id, $data['id']);
        $this->assertSame('gqlContactForm', $data['handle']);
        $this->assertSame('Contact', $data['name']);
        $this->assertCount(2, $data['fields']);

        $byId = [];
        foreach ($data['fields'] as $f) {
            $byId[$f['id']] = $f;
        }

        $this->assertSame('text', $byId[$nameId]['type']);
        $this->assertSame('Full Name', $byId[$nameId]['label']);
        $this->assertTrue($byId[$nameId]['required']);
        $this->assertSame('Your name', $byId[$nameId]['placeholder']);
        $this->assertSame([], $byId[$nameId]['options']);

        $this->assertSame('select', $byId[$colorId]['type']);
        $this->assertFalse($byId[$colorId]['required']);
        $this->assertSame(
            [['label' => 'Red', 'value' => 'red'], ['label' => 'Blue', 'value' => 'blue']],
            $byId[$colorId]['options'],
        );
    }

    public function testQueryLocalizesOptionLabelsPerSite(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm('Localized Options', 'gqlLocalizedOptionsForm', 'Localized Options', $siteId);
        $colorId = $this->createField($form->id, 'select', 'color', 'Colour', false, [
            'options' => [
                ['label' => 'Red', 'value' => 'red'],
                ['label' => 'Blue', 'value' => 'blue'],
            ],
        ]);

        // Translate one option for this site; the other must fall back to its source label.
        // Pass the array; the json column encodes once (matching FieldSyncService).
        Craft::$app->getDb()->createCommand()->update(
            '{{%simpleform_fields_sites}}',
            ['optionLabels' => ['red' => 'Rouge']],
            ['fieldId' => $colorId, 'siteId' => $siteId],
        )->execute();
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        $document = <<<'GQL'
        query ($handle: String!, $siteId: Int) {
            simpleForm(handle: $handle, siteId: $siteId) {
                fields { name type options { label value } }
            }
        }
        GQL;

        $result = $this->execute($document, ['simpleForms:read'], [
            'handle' => 'gqlLocalizedOptionsForm',
            'siteId' => $siteId,
        ]);

        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));

        $options = $result['data']['simpleForm']['fields'][0]['options'];
        // Localized label for the translated option, source-label fallback for the
        // other, and canonical values unchanged across the board.
        $this->assertSame(
            [['label' => 'Rouge', 'value' => 'red'], ['label' => 'Blue', 'value' => 'blue']],
            $options,
        );
    }

    /**
     * Names of the root query fields exposed for the given scope, via
     * introspection (which forces a full schema build so scope-gated fields are
     * either present or absent — not lazily skipped).
     *
     * @param list<string> $scope
     * @return list<string>
     */
    private function queryFieldNames(array $scope): array
    {
        $result = $this->execute('{ __schema { queryType { fields { name } } } }', $scope);
        $fields = $result['data']['__schema']['queryType']['fields'] ?? [];
        return array_column($fields, 'name');
    }

    /**
     * @param list<string> $scope
     * @return list<string>
     */
    private function mutationFieldNames(array $scope): array
    {
        $result = $this->execute('{ __schema { mutationType { fields { name } } } }', $scope);
        $fields = $result['data']['__schema']['mutationType']['fields'] ?? [];
        return array_column($fields, 'name');
    }

    public function testQueryScopeGating(): void
    {
        $this->requireCraft();

        // With the read scope, the form query is exposed.
        $this->assertContains('simpleForm', $this->queryFieldNames(['simpleForms:read']));
        $this->assertContains('simpleForms', $this->queryFieldNames(['simpleForms:read']));

        // Without it, the form query is absent from the schema entirely.
        $withoutScope = $this->queryFieldNames(['somethingElse:read']);
        $this->assertNotContains('simpleForm', $withoutScope);
        $this->assertNotContains('simpleForms', $withoutScope);
    }

    public function testSubmissionDataIsNotQueryable(): void
    {
        $this->requireCraft();

        // Privacy guarantee: even with both scopes granted, there is no query
        // that exposes stored submissions.
        $names = $this->queryFieldNames(['simpleForms:read', 'simpleFormSubmissions:create']);
        foreach ($names as $name) {
            $this->assertStringNotContainsStringIgnoringCase('submission', $name, "Unexpected submission query: $name");
        }
    }

    public function testSubmitMutationPersistsAndSendsEmail(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm(
            'Signup',
            'gqlSignupForm',
            'Signup',
            $siteId,
            emailTo: 'owner@example.com',
            emailSubject: 'New signup',
        );
        $nameId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);
        $emailId = $this->createField($form->id, 'email', 'email', 'Email', true);

        $document = <<<'GQL'
        mutation ($handle: String!, $siteId: Int, $values: [SimpleFormFieldValueInput!]!) {
            submitForm(handle: $handle, siteId: $siteId, values: $values) {
                success
                submissionId
                errors { key messages }
            }
        }
        GQL;

        $variables = [
            'handle' => 'gqlSignupForm',
            'siteId' => $siteId,
            'values' => [
                ['fieldId' => $nameId, 'value' => 'Ada Lovelace'],
                ['fieldId' => $emailId, 'value' => 'ada@example.com'],
            ],
        ];

        $result = [];
        $sent = $this->captureSentMessages(function () use ($document, $variables, &$result): void {
            $result = $this->execute($document, ['simpleFormSubmissions:create'], $variables);
        });

        $this->assertArrayNotHasKey('errors', $result, 'Mutation should not error: ' . json_encode($result['errors'] ?? null));

        $payload = $result['data']['submitForm'];
        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['errors']);
        $this->assertNotNull($payload['submissionId']);

        // Round-trips through the element query (same path the CP uses).
        $submission = Submission::find()->id($payload['submissionId'])->one();
        $this->assertInstanceOf(Submission::class, $submission);
        $this->assertSame((int) $form->id, $submission->formId);
        $this->assertSame('Ada Lovelace', $submission->data['field_' . $nameId]['value']);
        $this->assertSame('ada@example.com', $submission->data['field_' . $emailId]['value']);

        // The notification email fired through the shared submit path.
        $this->assertCount(1, $sent, 'Exactly one notification email should be sent');
        /** @var \craft\mail\Message $message */
        $message = $sent[0];
        $this->assertArrayHasKey('owner@example.com', $message->getTo());
        $this->assertSame('New signup', $message->getSubject());
    }

    public function testSubmitMutationNormalizesPhoneNumber(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm('Phone', 'gqlPhoneForm', 'Phone', $siteId);
        // Headless clients send a flat string value; the field normalizes it
        // against defaultCountry, identically to the AJAX path.
        $phoneId = $this->createField($form->id, 'phone', 'phone', 'Phone', true, [
            'showCountrySelector' => true,
            'defaultCountry' => 'CH',
        ]);

        $document = <<<'GQL'
        mutation ($handle: String!, $siteId: Int, $values: [SimpleFormFieldValueInput!]!) {
            submitForm(handle: $handle, siteId: $siteId, values: $values) {
                success
                submissionId
                errors { key messages }
            }
        }
        GQL;

        $variables = [
            'handle' => 'gqlPhoneForm',
            'siteId' => $siteId,
            'values' => [['fieldId' => $phoneId, 'value' => '079 123 45 67']],
        ];

        $result = $this->execute($document, ['simpleFormSubmissions:create'], $variables);

        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? null));
        $payload = $result['data']['submitForm'];
        $this->assertTrue($payload['success']);

        $submission = Submission::find()->id($payload['submissionId'])->one();
        $this->assertSame('+41791234567', $submission->data['field_' . $phoneId]['value']['e164']);
        $this->assertSame('CH', $submission->data['field_' . $phoneId]['value']['country']);
    }

    public function testSubmitMutationRejectsInvalidPhoneNumber(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm('PhoneBad', 'gqlPhoneBadForm', 'PhoneBad', $siteId);
        $phoneId = $this->createField($form->id, 'phone', 'phone', 'Phone', true, [
            'defaultCountry' => 'CH',
        ]);

        $document = <<<'GQL'
        mutation ($handle: String!, $siteId: Int, $values: [SimpleFormFieldValueInput!]!) {
            submitForm(handle: $handle, siteId: $siteId, values: $values) {
                success
                errors { key messages }
            }
        }
        GQL;

        $variables = [
            'handle' => 'gqlPhoneBadForm',
            'siteId' => $siteId,
            'values' => [['fieldId' => $phoneId, 'value' => 'abc']],
        ];

        $result = $this->execute($document, ['simpleFormSubmissions:create'], $variables);
        $payload = $result['data']['submitForm'];

        $this->assertFalse($payload['success']);
        $this->assertSame('field_' . $phoneId, $payload['errors'][0]['key']);
        $this->assertSame(['Enter a valid phone number.'], $payload['errors'][0]['messages']);
        $this->assertSame(0, Submission::find()->formId($form->id)->count());
    }

    public function testSubmitMutationEnforcesCaptchaUnlessBypassEnabled(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm('Captcha', 'gqlCaptchaForm', 'Captcha', $siteId);
        $nameId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $document = <<<'GQL'
        mutation ($handle: String!, $siteId: Int, $values: [SimpleFormFieldValueInput!]!, $captchaToken: String) {
            submitForm(handle: $handle, siteId: $siteId, values: $values, captchaToken: $captchaToken) {
                success
                errors { key messages }
            }
        }
        GQL;

        $baseVars = [
            'handle' => 'gqlCaptchaForm',
            'siteId' => $siteId,
            'values' => [['fieldId' => $nameId, 'value' => 'Ada']],
        ];

        $plugin = Plugin::getInstance();
        $originalCaptcha = $plugin->getCaptchaService();
        $plugin->set('captchaService', new StubCaptchaService());
        $settings = $plugin->getSettings();
        $originalBypass = $settings->allowGraphqlCaptchaBypass;

        try {
            // Bypass OFF (default): no/invalid token → captcha failure, nothing stored.
            $settings->allowGraphqlCaptchaBypass = false;
            $result = $this->execute($document, ['simpleFormSubmissions:create'], $baseVars);
            $payload = $result['data']['submitForm'];
            $this->assertFalse($payload['success'], 'submit without a captcha token must fail');
            $this->assertSame('captcha', $payload['errors'][0]['key']);
            $this->assertSame(0, Submission::find()->formId($form->id)->count());

            // Bypass OFF but a valid captcha token supplied → success.
            $result = $this->execute($document, ['simpleFormSubmissions:create'], $baseVars + ['captchaToken' => 'good-token']);
            $this->assertTrue($result['data']['submitForm']['success'], 'valid captcha token should pass');

            // Bypass ON: server-to-server caller with no token → success.
            $settings->allowGraphqlCaptchaBypass = true;
            $result = $this->execute($document, ['simpleFormSubmissions:create'], $baseVars);
            $this->assertTrue($result['data']['submitForm']['success'], 'bypass setting should skip captcha');
        } finally {
            $plugin->set('captchaService', $originalCaptcha);
            $settings->allowGraphqlCaptchaBypass = $originalBypass;
        }
    }

    public function testSubmitMutationIsRateLimited(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm('RL', 'gqlRateLimitForm', 'RL', $siteId);
        $nameId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $document = <<<'GQL'
        mutation ($handle: String!, $siteId: Int, $values: [SimpleFormFieldValueInput!]!) {
            submitForm(handle: $handle, siteId: $siteId, values: $values) {
                success
                errors { key messages }
            }
        }
        GQL;
        $vars = ['handle' => 'gqlRateLimitForm', 'siteId' => $siteId, 'values' => [['fieldId' => $nameId, 'value' => 'Ada']]];

        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->submitRateLimitPerMinute;
        $settings->submitRateLimitPerMinute = 2;
        Craft::$app->getCache()->flush();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.22';

        try {
            // The GraphQL path shares the front-end throttle (audit follow-up):
            // two succeed, the third is rejected.
            $this->assertTrue($this->execute($document, ['simpleFormSubmissions:create'], $vars)['data']['submitForm']['success']);
            $this->assertTrue($this->execute($document, ['simpleFormSubmissions:create'], $vars)['data']['submitForm']['success']);
            $blocked = $this->execute($document, ['simpleFormSubmissions:create'], $vars)['data']['submitForm'];
            $this->assertFalse($blocked['success'], 'third GraphQL submit should be rate limited');
            $this->assertSame('form', $blocked['errors'][0]['key']);
        } finally {
            $settings->submitRateLimitPerMinute = $original;
            Craft::$app->getCache()->flush();
        }
    }

    public function testSubmitMutationInvalidInputReturnsErrorsAndStoresNothing(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getPrimarySite()->id;
        $form = $this->createForm('Required', 'gqlRequiredForm', 'Required', $siteId);
        $requiredId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);

        $before = Submission::find()->formId($form->id)->count();

        $document = <<<'GQL'
        mutation ($handle: String!, $siteId: Int, $values: [SimpleFormFieldValueInput!]!) {
            submitForm(handle: $handle, siteId: $siteId, values: $values) {
                success
                submissionId
                errors { key messages }
            }
        }
        GQL;

        $result = $this->execute($document, ['simpleFormSubmissions:create'], [
            'handle' => 'gqlRequiredForm',
            'siteId' => $siteId,
            'values' => [
                ['fieldId' => $requiredId, 'value' => ''],
            ],
        ]);

        $this->assertArrayNotHasKey('errors', $result, 'Validation failure should be a payload error, not a hard GraphQL error');

        $payload = $result['data']['submitForm'];
        $this->assertFalse($payload['success']);
        $this->assertNull($payload['submissionId']);
        $this->assertNotEmpty($payload['errors']);

        $keys = array_column($payload['errors'], 'key');
        $this->assertContains('field_' . $requiredId, $keys);

        $after = Submission::find()->formId($form->id)->count();
        $this->assertSame($before, $after, 'No submission should be stored on validation failure');
    }

    public function testSubmitMutationScopeGating(): void
    {
        $this->requireCraft();

        // With the create scope, the submit mutation is exposed.
        $this->assertContains('submitForm', $this->mutationFieldNames(['simpleFormSubmissions:create']));

        // Without it, the submit mutation is absent from the schema entirely.
        $this->assertNotContains('submitForm', $this->mutationFieldNames(['simpleForms:read']));
    }
}

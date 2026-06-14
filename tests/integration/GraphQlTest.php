<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\models\GqlSchema;
use craft\test\TestMailer;
use fabianhaef\simpleform\elements\Submission;
use yii\mail\MessageInterface;

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

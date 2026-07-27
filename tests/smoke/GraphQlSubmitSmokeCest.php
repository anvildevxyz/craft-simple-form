<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use Craft;
use craft\models\GqlSchema;
use SmokeTester;

/**
 * GraphQL submit smoke tests: the public `submitForm` mutation creates a
 * submission through the same pipeline as the HTTP path, and surfaces validation
 * errors as structured payload (not a transport error).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class GraphQlSubmitSmokeCest extends BaseSmokeCest
{
    private const DOCUMENT = <<<'GQL'
    mutation ($handle: String!, $values: [SimpleFormFieldValueInput!]!) {
        submitForm(handle: $handle, values: $values) {
            success
            submissionId
            errors { key messages }
        }
    }
    GQL;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testSubmitMutationCreatesASubmission(SmokeTester $I): void
    {
        $form = $this->createForm('GQL', 'gqlSmoke' . uniqid());
        $nameId = $this->createField((int) $form->id, 'text', 'name', 'Name', true);
        $emailId = $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        $payload = $this->submitMutation($form->handle, [
            ['fieldId' => $nameId, 'value' => 'Ada'],
            ['fieldId' => $emailId, 'value' => 'ada@example.com'],
        ]);

        $I->assertTrue($payload['success']);
        $I->assertSame([], $payload['errors']);
        $I->assertNotNull($payload['submissionId']);

        $submission = Submission::find()->id($payload['submissionId'])->one();
        $I->assertNotNull($submission);
        $I->assertSame('ada@example.com', $submission->data['field_' . $emailId]['value']);
    }

    public function testSubmitMutationReturnsValidationErrors(SmokeTester $I): void
    {
        $form = $this->createForm('GQL', 'gqlSmokeErr' . uniqid());
        $nameId = $this->createField((int) $form->id, 'text', 'name', 'Name', true);
        $this->createField((int) $form->id, 'email', 'email', 'Email', true);

        // Required email omitted → structured validation failure, no transport error.
        $payload = $this->submitMutation($form->handle, [
            ['fieldId' => $nameId, 'value' => 'Ada'],
        ]);

        $I->assertFalse($payload['success']);
        $I->assertNotEmpty($payload['errors']);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * @param list<array{fieldId: int, value: string}> $values
     * @return array<string, mixed>
     */
    private function submitMutation(string $handle, array $values): array
    {
        $schema = new GqlSchema([
            'id' => 1,
            'uid' => 'smoke-schema-uid',
            'name' => 'Smoke Schema',
            'scope' => ['simpleFormSubmissions:create'],
        ]);

        $gql = Craft::$app->getGql();
        $gql->flushCaches();
        $gql->setActiveSchema($schema);

        $result = $gql->executeQuery($schema, self::DOCUMENT, ['handle' => $handle, 'values' => $values]);

        return $result['data']['submitForm'];
    }
}

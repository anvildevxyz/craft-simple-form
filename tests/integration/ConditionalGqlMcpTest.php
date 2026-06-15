<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\models\GqlSchema;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\tools\support\FieldOps;
use fabianhaef\simpleform\mcp\tools\support\FormPresenter;

/**
 * Conditional logic exposure through the headless surfaces: GraphQL
 * FormFieldType.conditional, and the MCP field write path (validation +
 * dangling-prune via FieldOps).
 *
 * @group requires-craft
 */
class ConditionalGqlMcpTest extends SimpleFormTestCase
{
    /**
     * @param string[] $scope
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function execute(string $document, array $scope, array $variables = []): array
    {
        $schema = new GqlSchema([
            'id' => 1,
            'uid' => 'test-schema-uid',
            'name' => 'Test Schema',
            'scope' => $scope,
        ]);

        $gqlService = Craft::$app->getGql();
        $gqlService->flushCaches();
        $gqlService->setActiveSchema($schema);

        return $gqlService->executeQuery($schema, $document, $variables);
    }

    public function testGraphQlExposesConditional(): void
    {
        $this->requireCraft();
        $siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $form = $this->createForm('GQL Cond', 'gqlCondForm', 'GQL Cond', $siteId);
        $this->createField($form->id, 'select', 'accountType', 'Account type', false, [
            'options' => [['label' => 'Personal', 'value' => 'personal'], ['label' => 'Business', 'value' => 'business']],
        ]);
        $this->createField($form->id, 'text', 'vat', 'VAT', false, [
            'conditional' => [
                'enabled' => true, 'action' => 'show', 'match' => 'all',
                'rules' => [['field' => 'accountType', 'operator' => 'eq', 'value' => 'business']],
                'required' => [
                    'enabled' => true, 'match' => 'all',
                    'rules' => [['field' => 'accountType', 'operator' => 'eq', 'value' => 'business']],
                ],
            ],
        ]);

        $document = 'query ($handle: String!, $siteId: Int!) {
            simpleForm(handle: $handle, siteId: $siteId) {
                fields {
                    name
                    conditional { action match rules { field operator value } requiredMatch requiredRules { field operator value } }
                }
            }
        }';

        $result = $this->execute($document, ['simpleForms:read'], ['handle' => 'gqlCondForm', 'siteId' => $siteId]);

        $this->assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? []));
        $fields = [];
        foreach ($result['data']['simpleForm']['fields'] as $f) {
            $fields[$f['name']] = $f;
        }

        // The trigger has no conditional.
        $this->assertNull($fields['accountType']['conditional']);

        // The dependent field exposes its rules.
        $cond = $fields['vat']['conditional'];
        $this->assertSame('show', $cond['action']);
        $this->assertSame('all', $cond['match']);
        $this->assertSame('accountType', $cond['rules'][0]['field']);
        $this->assertSame('eq', $cond['rules'][0]['operator']);
        $this->assertSame('business', $cond['rules'][0]['value']);
        $this->assertSame('all', $cond['requiredMatch']);
        $this->assertSame('accountType', $cond['requiredRules'][0]['field']);
    }

    public function testMcpRejectsSelfReference(): void
    {
        $this->requireCraft();
        $form = $this->createForm('MCP Self', 'mcpSelfForm', 'MCP Self');

        $errors = FieldOps::validate('text', 'Loopy', 'loopy', [
            'conditional' => [
                'enabled' => true, 'action' => 'show', 'match' => 'all',
                'rules' => [['field' => 'loopy', 'operator' => 'notEmpty', 'value' => '']],
            ],
        ], (int) $form->id, null);

        $this->assertArrayHasKey('config', $errors);
        $this->assertStringContainsStringIgnoringCase('itself', implode(' ', $errors['config']));
    }

    public function testMcpAddPrunesDanglingConditional(): void
    {
        $this->requireCraft();
        $form = $this->createForm('MCP Prune', 'mcpPruneForm', 'MCP Prune');

        // Reference a handle that does not exist in the form -> pruned on add.
        FieldOps::add((int) $form->id, 'text', 'notes', 'Notes', false, '', [
            'conditional' => [
                'enabled' => true, 'action' => 'show', 'match' => 'all',
                'rules' => [['field' => 'ghost', 'operator' => 'eq', 'value' => 'x']],
            ],
        ]);

        $fresh = Form::find()->id($form->id)->siteId($form->siteId)->one();
        $fields = FormPresenter::fields($fresh);
        $notes = null;
        foreach ($fields as $f) {
            if ($f['handle'] === 'notes') {
                $notes = $f;
            }
        }

        $this->assertNotNull($notes);
        $this->assertArrayNotHasKey('conditional', $notes['config'], 'Dangling MCP-authored conditional must be pruned');
    }

    public function testMcpAddKeepsValidConditional(): void
    {
        $this->requireCraft();
        $form = $this->createForm('MCP Keep', 'mcpKeepForm', 'MCP Keep');
        $this->createField($form->id, 'select', 'plan', 'Plan', false, [
            'options' => [['label' => 'Pro', 'value' => 'pro']],
        ]);

        FieldOps::add((int) $form->id, 'text', 'seats', 'Seats', false, '', [
            'conditional' => [
                'enabled' => true, 'action' => 'show', 'match' => 'all',
                'rules' => [['field' => 'plan', 'operator' => 'eq', 'value' => 'pro']],
            ],
        ]);

        $fresh = Form::find()->id($form->id)->siteId($form->siteId)->one();
        $seats = null;
        foreach (FormPresenter::fields($fresh) as $f) {
            if ($f['handle'] === 'seats') {
                $seats = $f;
            }
        }

        $this->assertNotNull($seats);
        $this->assertArrayHasKey('conditional', $seats['config']);
        $this->assertSame('plan', $seats['config']['conditional']['rules'][0]['field']);
    }
}

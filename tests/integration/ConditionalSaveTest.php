<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\helpers\FieldQueryHelper;
use anvildev\simpleform\services\FieldSyncService;

/**
 * Save-time conditional guarantees through FieldSyncService: self-reference and
 * cyclic rules are rejected by validate(), and rules pointing at a removed
 * field are pruned on sync().
 *
 * @group requires-craft
 */
class ConditionalSaveTest extends SimpleFormTestCase
{
    private function item(string $handle, string $label, array $config = []): array
    {
        return [
            'id' => null,
            'type' => 'text',
            'handle' => $handle,
            'label' => $label,
            'required' => false,
            'helpText' => '',
            'errorMessage' => '',
            'config' => $config,
        ];
    }

    private function showWhen(string $targetHandle): array
    {
        return ['conditional' => [
            'enabled' => true, 'action' => 'show', 'match' => 'all',
            'rules' => [['field' => $targetHandle, 'operator' => 'notEmpty', 'value' => '']],
        ]];
    }

    public function testValidConditionalPassesValidation(): void
    {
        $this->requireCraft();
        $sync = new FieldSyncService();

        $errors = $sync->validate([
            $this->item('trigger', 'Trigger'),
            $this->item('dependent', 'Dependent', $this->showWhen('trigger')),
        ]);

        $this->assertSame([], $errors);
    }

    public function testSelfReferenceIsRejected(): void
    {
        $this->requireCraft();
        $sync = new FieldSyncService();

        $errors = $sync->validate([
            $this->item('loopy', 'Loopy', $this->showWhen('loopy')),
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsStringIgnoringCase('itself', implode(' ', $errors));
    }

    public function testCycleIsRejected(): void
    {
        $this->requireCraft();
        $sync = new FieldSyncService();

        // a shows-when b, b shows-when a -> circular.
        $errors = $sync->validate([
            $this->item('a', 'A', $this->showWhen('b')),
            $this->item('b', 'B', $this->showWhen('a')),
        ]);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsStringIgnoringCase('circular', implode(' ', $errors));
    }

    public function testDanglingReferenceIsPrunedOnSync(): void
    {
        $this->requireCraft();
        $sync = new FieldSyncService();
        $form = $this->createForm('Cond Prune', 'condPruneForm', 'Cond Prune');

        // First save: dependent shows-when trigger (valid).
        $sync->sync($form, [
            $this->item('trigger', 'Trigger'),
            $this->item('dependent', 'Dependent', $this->showWhen('trigger')),
        ], $form->siteId);

        // Second save: trigger removed -> dependent's rule now dangles.
        $sync->sync($form, [
            $this->item('dependent', 'Dependent', $this->showWhen('trigger')),
        ], $form->siteId);

        $rows = FieldQueryHelper::fieldsForForm((int) $form->id, (int) $form->siteId);
        $dependent = null;
        foreach ($rows as $row) {
            if ($row['name'] === 'dependent') {
                $dependent = $row;
            }
        }

        $this->assertNotNull($dependent);
        $this->assertArrayNotHasKey('conditional', $dependent['config'], 'Dangling conditional must be pruned on save');
    }
}

<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\services\FieldSyncService;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for FieldSyncService::sanitizeConditional() — the save-time prune
 * that keeps persisted conditional rules referencing only live peer fields.
 */
class ConditionalSanitizeTest extends TestCase
{
    public function testKeepsRulesReferencingPresentHandles(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'show', 'match' => 'all',
            'rules' => [['field' => 'accountType', 'operator' => 'eq', 'value' => 'business']],
        ]];

        $out = FieldSyncService::sanitizeConditional($config, ['accountType' => true, 'vat' => true], 'vat');

        $this->assertCount(1, $out['conditional']['rules']);
        $this->assertSame('accountType', $out['conditional']['rules'][0]['field']);
    }

    public function testPrunesRuleReferencingRemovedHandle(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'show', 'match' => 'all',
            'rules' => [['field' => 'goneField', 'operator' => 'eq', 'value' => 'x']],
        ]];

        // Target handle not in the set -> the only rule drops, so the whole
        // conditional block is removed.
        $out = FieldSyncService::sanitizeConditional($config, ['vat' => true], 'vat');

        $this->assertArrayNotHasKey('conditional', $out);
    }

    public function testPrunesSelfReference(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'show', 'match' => 'all',
            'rules' => [['field' => 'vat', 'operator' => 'notEmpty', 'value' => '']],
        ]];

        $out = FieldSyncService::sanitizeConditional($config, ['vat' => true], 'vat');

        $this->assertArrayNotHasKey('conditional', $out);
    }

    public function testPrunesRequiredBlockButKeepsVisibility(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'show', 'match' => 'all',
            'rules' => [['field' => 'accountType', 'operator' => 'eq', 'value' => 'business']],
            'required' => [
                'enabled' => true, 'match' => 'all',
                'rules' => [['field' => 'goneField', 'operator' => 'eq', 'value' => 'x']],
            ],
        ]];

        $out = FieldSyncService::sanitizeConditional($config, ['accountType' => true, 'vat' => true], 'vat');

        $this->assertCount(1, $out['conditional']['rules']);
        $this->assertArrayNotHasKey('required', $out['conditional'], 'Empty required block should be dropped');
    }

    public function testLeavesConfigWithoutConditionalUntouched(): void
    {
        $config = ['options' => [['value' => 'a', 'label' => 'A']], 'required' => true];

        $out = FieldSyncService::sanitizeConditional($config, ['x' => true], 'x');

        $this->assertSame($config, $out);
    }
}

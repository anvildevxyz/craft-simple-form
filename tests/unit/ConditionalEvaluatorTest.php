<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\helpers\ConditionalEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the conditional-logic evaluator. This is pure PHP with
 * no Craft dependency, so it asserts real evaluation results (not just source
 * shape). The front-end JS evaluator must produce identical results for the
 * same operator/value table — see tests/unit/js parity expectations in the PRD.
 */
class ConditionalEvaluatorTest extends TestCase
{
    // --- Defaults / backward compatibility -------------------------------

    public function testNoConditionalBlockIsAlwaysVisible(): void
    {
        $this->assertTrue(ConditionalEvaluator::isVisible([], []));
        $this->assertTrue(ConditionalEvaluator::isVisible(['required' => true], [1 => 'x']));
    }

    public function testDisabledOrEmptyRulesAreVisible(): void
    {
        $this->assertTrue(ConditionalEvaluator::isVisible([
            'conditional' => ['enabled' => false, 'action' => 'show', 'rules' => [['fieldId' => 1, 'operator' => 'eq', 'value' => 'x']]],
        ], [1 => 'y']));

        $this->assertTrue(ConditionalEvaluator::isVisible([
            'conditional' => ['enabled' => true, 'action' => 'show', 'rules' => []],
        ], [1 => 'y']));
    }

    // --- show / hide actions ---------------------------------------------

    public function testShowWhenRuleMatches(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'show', 'match' => 'all',
            'rules' => [['fieldId' => 1, 'operator' => 'eq', 'value' => 'business']],
        ]];

        $this->assertTrue(ConditionalEvaluator::isVisible($config, [1 => 'business']));
        $this->assertFalse(ConditionalEvaluator::isVisible($config, [1 => 'personal']));
        $this->assertFalse(ConditionalEvaluator::isVisible($config, [1 => null]));
    }

    public function testHideWhenRuleMatches(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'hide', 'match' => 'all',
            'rules' => [['fieldId' => 1, 'operator' => 'eq', 'value' => 'business']],
        ]];

        $this->assertFalse(ConditionalEvaluator::isVisible($config, [1 => 'business']));
        $this->assertTrue(ConditionalEvaluator::isVisible($config, [1 => 'personal']));
    }

    // --- match all (AND) / any (OR) --------------------------------------

    public function testMatchAllRequiresEveryRule(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'show', 'match' => 'all',
            'rules' => [
                ['fieldId' => 1, 'operator' => 'eq', 'value' => 'a'],
                ['fieldId' => 2, 'operator' => 'eq', 'value' => 'b'],
            ],
        ]];

        $this->assertTrue(ConditionalEvaluator::isVisible($config, [1 => 'a', 2 => 'b']));
        $this->assertFalse(ConditionalEvaluator::isVisible($config, [1 => 'a', 2 => 'x']));
    }

    public function testMatchAnyRequiresOneRule(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'show', 'match' => 'any',
            'rules' => [
                ['fieldId' => 1, 'operator' => 'eq', 'value' => 'a'],
                ['fieldId' => 2, 'operator' => 'eq', 'value' => 'b'],
            ],
        ]];

        $this->assertTrue(ConditionalEvaluator::isVisible($config, [1 => 'a', 2 => 'x']));
        $this->assertTrue(ConditionalEvaluator::isVisible($config, [1 => 'x', 2 => 'b']));
        $this->assertFalse(ConditionalEvaluator::isVisible($config, [1 => 'x', 2 => 'y']));
    }

    // --- operator matrix --------------------------------------------------

    /**
     * @dataProvider operatorProvider
     */
    public function testOperators(string $operator, mixed $actual, mixed $expected, bool $result): void
    {
        $this->assertSame($result, ConditionalEvaluator::compare($operator, $actual, $expected));
    }

    /**
     * @return array<string, array{0: string, 1: mixed, 2: mixed, 3: bool}>
     */
    public static function operatorProvider(): array
    {
        return [
            'eq match' => ['eq', 'us', 'us', true],
            'eq miss' => ['eq', 'ca', 'us', false],
            'eq array member' => ['eq', ['us', 'ca'], 'ca', true],
            'eq array miss' => ['eq', ['us', 'ca'], 'mx', false],
            'eq zero-string is value' => ['eq', '0', '0', true],
            'neq match' => ['neq', 'ca', 'us', true],
            'neq miss' => ['neq', 'us', 'us', false],
            'empty null' => ['empty', null, '', true],
            'empty string' => ['empty', '', '', true],
            'empty array' => ['empty', [], '', true],
            'empty zero-string is NOT empty' => ['empty', '0', '', false],
            'empty filled' => ['empty', 'x', '', false],
            'notEmpty filled' => ['notEmpty', 'x', '', true],
            'notEmpty blank' => ['notEmpty', '', '', false],
            'contains substring' => ['contains', 'hello world', 'world', true],
            'contains substring miss' => ['contains', 'hello', 'world', false],
            'contains array member' => ['contains', ['a', 'b'], 'b', true],
            'contains array miss' => ['contains', ['a', 'b'], 'c', false],
            'gt numeric true' => ['gt', '10', '5', true],
            'gt numeric false' => ['gt', '3', '5', false],
            'lt numeric true' => ['lt', '3', '5', true],
            'gt non-numeric is false' => ['gt', 'abc', '5', false],
            'gt date true' => ['gt', '2026-06-15', '2026-01-01', true],
            'lt date true' => ['lt', '2026-01-01', '2026-06-15', true],
            'unknown operator never matches' => ['bogus', 'x', 'x', false],
        ];
    }

    // --- conditional required --------------------------------------------

    public function testNoRequiredBlockMeansNoConditionalRequirement(): void
    {
        $this->assertFalse(ConditionalEvaluator::isRequiredByCondition([], [1 => 'x']));
        $this->assertFalse(ConditionalEvaluator::isRequiredByCondition([
            'conditional' => ['enabled' => true, 'action' => 'show', 'rules' => [['fieldId' => 1, 'operator' => 'eq', 'value' => 'x']]],
        ], [1 => 'x']));
    }

    public function testConditionalRequiredTriggers(): void
    {
        $config = ['conditional' => [
            'enabled' => true,
            'required' => [
                'enabled' => true, 'match' => 'all',
                'rules' => [['fieldId' => 3, 'operator' => 'eq', 'value' => 'other']],
            ],
        ]];

        $this->assertTrue(ConditionalEvaluator::isRequiredByCondition($config, [3 => 'other']));
        $this->assertFalse(ConditionalEvaluator::isRequiredByCondition($config, [3 => 'reason-a']));
    }

    public function testRequiredIsIndependentOfVisibilityBlock(): void
    {
        // A field with only a required block (no visibility rules) is visible
        // but conditionally required.
        $config = ['conditional' => [
            'enabled' => true,
            'required' => [
                'enabled' => true, 'match' => 'any',
                'rules' => [['fieldId' => 1, 'operator' => 'notEmpty', 'value' => '']],
            ],
        ]];

        $this->assertTrue(ConditionalEvaluator::isVisible($config, [1 => 'anything']));
        $this->assertTrue(ConditionalEvaluator::isRequiredByCondition($config, [1 => 'anything']));
        $this->assertFalse(ConditionalEvaluator::isRequiredByCondition($config, [1 => '']));
    }

    // --- referenced ids (for cycle/self-ref detection) -------------------

    public function testReferencedFieldIdsCollectsBothBlocks(): void
    {
        $config = ['conditional' => [
            'enabled' => true, 'action' => 'show',
            'rules' => [
                ['fieldId' => 1, 'operator' => 'eq', 'value' => 'a'],
                ['fieldId' => 2, 'operator' => 'eq', 'value' => 'b'],
            ],
            'required' => [
                'enabled' => true,
                'rules' => [['fieldId' => 2, 'operator' => 'eq', 'value' => 'c']],
            ],
        ]];

        $this->assertSame([1, 2], ConditionalEvaluator::referencedFieldIds($config));
        $this->assertSame([], ConditionalEvaluator::referencedFieldIds([]));
    }

    // --- unknown / deleted target fields ---------------------------------

    public function testUnknownTargetFieldEvaluatesAsEmpty(): void
    {
        // Rule references field 99 which is not in the values map.
        $configEq = ['conditional' => [
            'enabled' => true, 'action' => 'show',
            'rules' => [['fieldId' => 99, 'operator' => 'eq', 'value' => 'x']],
        ]];
        $this->assertFalse(ConditionalEvaluator::isVisible($configEq, [1 => 'x']));

        $configEmpty = ['conditional' => [
            'enabled' => true, 'action' => 'show',
            'rules' => [['fieldId' => 99, 'operator' => 'empty', 'value' => '']],
        ]];
        $this->assertTrue(ConditionalEvaluator::isVisible($configEmpty, [1 => 'x']));
    }
}

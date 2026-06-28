<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\helpers\JumpResolver;
use PHPUnit\Framework\TestCase;

/**
 * Logic-jump resolution (#245). Mirrors tests/js/jump-resolver.test.js so the
 * PHP {@see JumpResolver} and the front-end SF.jumps stay in lock-step — the
 * branch the visitor navigates and the steps the server validates must agree.
 */
class JumpResolverTest extends TestCase
{
    /** @var list<list<array{field: string, operator: string, value: mixed, to: int}>> */
    private array $rules = [
        [['field' => 'plan', 'operator' => 'eq', 'value' => 'enterprise', 'to' => 2]],
        [],
        [],
    ];

    public function testNextTakesOrSkipsTheJump(): void
    {
        $this->assertSame(2, JumpResolver::next($this->rules, 0, ['plan' => 'enterprise']));
        $this->assertSame(1, JumpResolver::next($this->rules, 0, ['plan' => 'basic']));
        $this->assertSame(2, JumpResolver::next($this->rules, 1, ['plan' => 'enterprise']), 'no rules → sequential');
    }

    public function testReachablePath(): void
    {
        $this->assertSame([0, 2], JumpResolver::reachable($this->rules, 3, ['plan' => 'enterprise']));
        $this->assertSame([0, 1, 2], JumpResolver::reachable($this->rules, 3, ['plan' => 'basic']));
    }

    public function testFirstMatchingRuleWins(): void
    {
        $multi = [
            [
                ['field' => 'f', 'operator' => 'eq', 'value' => 'a', 'to' => 3],
                ['field' => 'f', 'operator' => 'eq', 'value' => 'b', 'to' => 2],
            ],
            [], [], [],
        ];
        $this->assertSame(2, JumpResolver::next($multi, 0, ['f' => 'b']));
        $this->assertSame(3, JumpResolver::next($multi, 0, ['f' => 'a']));
        $this->assertSame(1, JumpResolver::next($multi, 0, ['f' => 'z']));
        $this->assertSame([0, 3], JumpResolver::reachable($multi, 4, ['f' => 'a']));
    }

    public function testContainsOperatorOnMultiValueAnswer(): void
    {
        $contains = [[['field' => 'tags', 'operator' => 'contains', 'value' => 'vip', 'to' => 2]], [], []];
        $this->assertSame(2, JumpResolver::next($contains, 0, ['tags' => ['new', 'vip']]));
        $this->assertSame(1, JumpResolver::next($contains, 0, ['tags' => ['new']]));
    }

    public function testBuildStepRulesResolvesForwardTargetsOnly(): void
    {
        // Sequence: step0=[plan], step1=[size], step2=[email].
        $sequence = [['plan'], ['size'], ['email']];
        $configs = [
            'plan' => ['jumps' => [['operator' => 'eq', 'value' => 'enterprise', 'target' => 'email']]],
            // A backward jump (target on an earlier step) must be dropped.
            'email' => ['jumps' => [['operator' => 'eq', 'value' => 'x', 'target' => 'plan']]],
            // A dangling target must be dropped.
            'size' => ['jumps' => [['operator' => 'eq', 'value' => 'big', 'target' => 'ghost']]],
        ];

        $stepRules = JumpResolver::buildStepRules($sequence, $configs);

        $this->assertSame([['field' => 'plan', 'operator' => 'eq', 'value' => 'enterprise', 'to' => 2]], $stepRules[0]);
        $this->assertSame([], $stepRules[1], 'dangling target dropped');
        $this->assertSame([], $stepRules[2], 'backward target dropped');
    }
}

<?php

namespace fabianhaef\simpleform\tests\integration;

use fabianhaef\simpleform\Plugin;

/**
 * #245 — logic-jump save validation through the real FieldSyncService::validate()
 * chokepoint (the FormsController save path), so a backward/same-step jump is
 * rejected before any DB write, while a forward jump and a dangling target pass.
 */
class JumpValidationTest extends SimpleFormTestCase
{
    /**
     * @param array<int, array<string, mixed>> $extraConfigByHandle
     * @return array<int, array<string, mixed>>
     */
    private static function items(string $jumpOn, string $target, array $pages): array
    {
        $items = [];
        foreach (['q1', 'q2', 'q3'] as $h) {
            $config = ['page' => $pages[$h] ?? 1];
            if ($h === $jumpOn) {
                $config['jumps'] = [['operator' => 'eq', 'value' => 'x', 'target' => $target]];
            }
            $items[] = ['handle' => $h, 'label' => strtoupper($h), 'type' => 'text', 'config' => $config];
        }

        return $items;
    }

    public function testBackwardJumpIsRejectedAtValidate(): void
    {
        $this->requireCraft();
        $sync = Plugin::getInstance()->getFieldSync();

        // q3 (page 2) jumps back to q1 (page 1).
        $errors = $sync->validate(self::items('q3', 'q1', ['q1' => 1, 'q2' => 1, 'q3' => 2]));
        $this->assertNotEmpty($errors, 'a backward jump must be rejected');
    }

    public function testForwardJumpPassesValidate(): void
    {
        $this->requireCraft();
        $sync = Plugin::getInstance()->getFieldSync();

        $errors = $sync->validate(self::items('q1', 'q3', ['q1' => 1, 'q2' => 1, 'q3' => 2]));
        $this->assertSame([], $errors, 'a forward jump must pass');
    }

    public function testDanglingTargetPassesValidate(): void
    {
        $this->requireCraft();
        $sync = Plugin::getInstance()->getFieldSync();

        // Target not in the set (removed in the same edit) — pruned on save, not errored.
        $errors = $sync->validate(self::items('q1', 'removedField', ['q1' => 1, 'q2' => 1, 'q3' => 2]));
        $this->assertSame([], $errors);
    }

    public function testSameStepJumpRejectedOnlyInStandardMode(): void
    {
        $this->requireCraft();
        $sync = Plugin::getInstance()->getFieldSync();

        // q1 → q3, both on page 1. Standard: same step → rejected.
        $this->assertNotEmpty($sync->validate(self::items('q1', 'q3', []), false));
        // Conversational: each field is its own screen, so q1 → q3 is forward → ok.
        $this->assertSame([], $sync->validate(self::items('q1', 'q3', []), true));
    }
}

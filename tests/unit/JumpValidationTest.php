<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\services\FieldSyncService;
use PHPUnit\Framework\TestCase;

/**
 * #245 — save-time validation of logic-jump targets (FieldSyncService::jumpSetErrors).
 * A jump must point to a field on a strictly later step/screen; a backward or
 * same-step target is a hard error, while a target removed in the same edit
 * (dangling) is left to the on-save prune, not errored. Pure (no Craft boot).
 */
class JumpValidationTest extends TestCase
{
    private const LAYOUT = ['heading', 'divider', 'html'];

    /**
     * @param array<string, mixed> $jumpsByHandle handle => list of jump rules
     * @param array<string, int> $pageByHandle handle => page number (default 1)
     * @return array<int, array<string, mixed>>
     */
    private static function items(array $jumpsByHandle, array $pageByHandle = []): array
    {
        $items = [];
        foreach (['q1', 'q2', 'q3'] as $h) {
            $config = ['page' => $pageByHandle[$h] ?? 1];
            if (isset($jumpsByHandle[$h])) {
                $config['jumps'] = $jumpsByHandle[$h];
            }
            $items[] = ['handle' => $h, 'label' => strtoupper($h), 'type' => 'text', 'config' => $config];
        }

        return $items;
    }

    /**
     * @return array<string, string>
     */
    private static function jump(string $target): array
    {
        return ['operator' => 'eq', 'value' => 'x', 'target' => $target];
    }

    public function testForwardJumpAcrossPagesIsValid(): void
    {
        // q1 on page 1 jumps to q3 on page 2 — strictly forward.
        $items = self::items(['q1' => [self::jump('q3')]], ['q1' => 1, 'q2' => 1, 'q3' => 2]);
        $this->assertSame([], FieldSyncService::jumpSetErrors($items, false, self::LAYOUT));
    }

    public function testBackwardJumpIsRejected(): void
    {
        // q3 on page 2 jumps back to q1 on page 1.
        $items = self::items(['q3' => [self::jump('q1')]], ['q1' => 1, 'q2' => 1, 'q3' => 2]);
        $errors = FieldSyncService::jumpSetErrors($items, false, self::LAYOUT);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Q3', $errors[0]);
        $this->assertStringContainsString('q1', $errors[0]);
    }

    public function testSameStepJumpIsRejected(): void
    {
        // q1 and q3 both on page 1 (same step) — a jump between them can't route.
        $items = self::items(['q1' => [self::jump('q3')]]);
        $errors = FieldSyncService::jumpSetErrors($items, false, self::LAYOUT);
        $this->assertCount(1, $errors);
    }

    public function testSelfTargetIsRejected(): void
    {
        $items = self::items(['q1' => [self::jump('q1')]]);
        $this->assertCount(1, FieldSyncService::jumpSetErrors($items, false, self::LAYOUT));
    }

    public function testDanglingTargetIsNotErrored(): void
    {
        // Target 'gone' is not in the set (removed in this edit) — pruned on save,
        // not a hard error.
        $items = self::items(['q1' => [self::jump('gone')]]);
        $this->assertSame([], FieldSyncService::jumpSetErrors($items, false, self::LAYOUT));
    }

    public function testConversationalForwardJumpIsValid(): void
    {
        // Single page + conversational: each field is its own screen in order, so
        // q1 → q3 is forward.
        $items = self::items(['q1' => [self::jump('q3')]]);
        $this->assertSame([], FieldSyncService::jumpSetErrors($items, true, self::LAYOUT));
    }

    public function testConversationalBackwardJumpIsRejected(): void
    {
        $items = self::items(['q3' => [self::jump('q1')]]);
        $this->assertCount(1, FieldSyncService::jumpSetErrors($items, true, self::LAYOUT));
    }

    public function testFieldWithoutJumpsProducesNoError(): void
    {
        $this->assertSame([], FieldSyncService::jumpSetErrors(self::items([]), false, self::LAYOUT));
    }
}

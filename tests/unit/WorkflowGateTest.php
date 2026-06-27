<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\services\WorkflowService;
use PHPUnit\Framework\TestCase;

/**
 * #248 — the pure transition gate: which configured transitions a user may take
 * from a given stage, honoring the per-transition user-group allow-list (empty =
 * anyone; admin always passes). No Craft boot — the full transition (persist +
 * audit + event) is covered by the integration suite.
 */
class WorkflowGateTest extends TestCase
{
    /**
     * @return list<array{from: string, to: string, label: string, groups: list<string>}>
     */
    private static function transitions(): array
    {
        return [
            ['from' => 'submitted', 'to' => 'review', 'label' => 'Send to review', 'groups' => []],
            ['from' => 'review', 'to' => 'approved', 'label' => 'Approve', 'groups' => ['managers']],
            ['from' => 'review', 'to' => 'rejected', 'label' => 'Reject', 'groups' => ['managers', 'leads']],
            ['from' => 'approved', 'to' => 'submitted', 'label' => 'Reopen', 'groups' => ['admins']],
        ];
    }

    public function testOnlyTransitionsFromTheCurrentStageAreReturned(): void
    {
        $out = WorkflowService::filterAllowed(self::transitions(), 'submitted', [], false);
        $this->assertCount(1, $out);
        $this->assertSame('review', $out[0]['to']);
    }

    public function testUngatedTransitionIsAllowedForAnyone(): void
    {
        // 'submitted → review' has an empty group gate.
        $out = WorkflowService::filterAllowed(self::transitions(), 'submitted', [], false);
        $this->assertNotSame([], $out);
    }

    public function testGatedTransitionHiddenWithoutMatchingGroup(): void
    {
        // A user in no relevant group sees neither gated 'review' transition.
        $out = WorkflowService::filterAllowed(self::transitions(), 'review', ['authors'], false);
        $this->assertSame([], $out);
    }

    public function testGatedTransitionVisibleWithMatchingGroup(): void
    {
        $out = WorkflowService::filterAllowed(self::transitions(), 'review', ['leads'], false);
        // Only 'reject' lists 'leads'; 'approve' is managers-only.
        $this->assertCount(1, $out);
        $this->assertSame('rejected', $out[0]['to']);
    }

    public function testAdminBypassesEveryGroupGate(): void
    {
        $out = WorkflowService::filterAllowed(self::transitions(), 'review', [], true);
        $this->assertCount(2, $out);
    }

    public function testNullStageMatchesTransitionsWithEmptyFrom(): void
    {
        // No transition has an empty `from`, so a null current stage yields none.
        $out = WorkflowService::filterAllowed(self::transitions(), null, [], true);
        $this->assertSame([], $out);
    }
}

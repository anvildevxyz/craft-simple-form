<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\services\FieldSyncService;
use SmokeTester;

/**
 * Logic jumps smoke tests (#245): a branching form emits the resolved jump table
 * as `data-sf-jumps` for the navigator, a non-branching form emits none, and a
 * backward jump target is rejected at save (#245 save validation).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class LogicJumpsSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testForwardJumpRendersAsDataAttribute(SmokeTester $I): void
    {
        $form = $this->createForm('Jumps', 'jumpRender' . uniqid());
        // q1 (screen 0) jumps forward to q3 (screen 2) when its answer is "skip".
        $this->createField((int) $form->id, 'text', 'q1', 'Q1', false, [
            'jumps' => [['operator' => 'eq', 'value' => 'skip', 'target' => 'q3']],
        ]);
        $this->createField((int) $form->id, 'text', 'q2', 'Q2');
        $this->createField((int) $form->id, 'text', 'q3', 'Q3');

        $form = $this->reloadForm($form);
        $form->renderMode = 'conversational';
        Craft::$app->getElements()->saveElement($form);

        $html = $this->renderForm($form->handle);
        // The attribute carries a JSON array of per-screen rules (HTML-escaped by
        // Twig, so the inner quotes render as &quot;).
        $I->assertStringContainsString('data-sf-jumps="[', $html);
        // The resolved rule targets q3's screen index (2).
        $I->assertStringContainsString('to&quot;:2', $html);
    }

    public function testNonBranchingFormEmitsNoJumpAttribute(SmokeTester $I): void
    {
        $form = $this->createForm('NoJumps', 'jumpNone' . uniqid());
        $this->createField((int) $form->id, 'text', 'q1', 'Q1');
        $this->createField((int) $form->id, 'text', 'q2', 'Q2');

        $form = $this->reloadForm($form);
        $form->renderMode = 'conversational';
        Craft::$app->getElements()->saveElement($form);

        $I->assertStringNotContainsString('data-sf-jumps', $this->renderForm($form->handle));
    }

    public function testBackwardJumpIsRejectedAtSave(SmokeTester $I): void
    {
        $sync = new FieldSyncService();
        // q3 (page 2) jumps back to q1 (page 1) — not strictly forward.
        $items = [
            ['handle' => 'q1', 'label' => 'Q1', 'type' => 'text', 'config' => ['page' => 1]],
            ['handle' => 'q3', 'label' => 'Q3', 'type' => 'text', 'config' => [
                'page' => 2,
                'jumps' => [['operator' => 'eq', 'value' => 'x', 'target' => 'q1']],
            ]],
        ];

        $I->assertNotEmpty($sync->validate($items), 'a backward jump must be rejected at save');
    }

    public function testForwardJumpPassesSave(SmokeTester $I): void
    {
        $sync = new FieldSyncService();
        $items = [
            ['handle' => 'q1', 'label' => 'Q1', 'type' => 'text', 'config' => [
                'page' => 1,
                'jumps' => [['operator' => 'eq', 'value' => 'x', 'target' => 'q3']],
            ]],
            ['handle' => 'q3', 'label' => 'Q3', 'type' => 'text', 'config' => ['page' => 2]],
        ];

        $I->assertSame([], $sync->validate($items));
    }
}

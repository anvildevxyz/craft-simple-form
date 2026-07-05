<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\services\FieldSyncService;
use PHPUnit\Framework\TestCase;

/**
 * #288 — deleting a builder field was one unconfirmed click, and rules on
 * OTHER fields referencing it were pruned silently server-side on save.
 * Covers the new pure pruned-reference detector and the JS/controller wiring.
 */
class BuilderDeleteSafetyTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/' . $relativePath);
    }

    // =========================================================================
    // prunedRuleReferences (pure)
    // =========================================================================

    public function testDetectsVisibilityRulePointingAtMissingField(): void
    {
        $items = [
            ['handle' => 'b', 'config' => ['conditional' => ['enabled' => true, 'rules' => [['field' => 'deleted']]]]],
        ];

        $this->assertSame(['b' => ['deleted']], FieldSyncService::prunedRuleReferences($items));
    }

    public function testDetectsRequiredRulesAndJumps(): void
    {
        $items = [
            ['handle' => 'a', 'config' => ['jumps' => [['target' => 'gone']]]],
            ['handle' => 'b', 'config' => ['conditional' => ['required' => ['enabled' => true, 'rules' => [['field' => 'a']]]]]],
        ];

        // The jump target is missing; b's required rule points at a live field.
        $this->assertSame(['a' => ['gone']], FieldSyncService::prunedRuleReferences($items));
    }

    public function testSelfReferenceCountsAsPruned(): void
    {
        $items = [
            ['handle' => 'a', 'config' => ['conditional' => ['enabled' => true, 'rules' => [['field' => 'a']]]]],
        ];

        $this->assertSame(['a' => ['a']], FieldSyncService::prunedRuleReferences($items));
    }

    public function testCleanSetReportsNothing(): void
    {
        $items = [
            ['handle' => 'a', 'config' => []],
            ['handle' => 'b', 'config' => ['conditional' => ['enabled' => true, 'rules' => [['field' => 'a']]]]],
        ];

        $this->assertSame([], FieldSyncService::prunedRuleReferences($items));
    }

    public function testDetectorAgreesWithTheSanitizer(): void
    {
        // Whatever the detector reports, sanitizeConditional() actually drops —
        // and what it doesn't report survives.
        $valid = ['a' => true, 'b' => true];
        $config = ['conditional' => ['enabled' => true, 'rules' => [['field' => 'a'], ['field' => 'deleted']]]];

        $sanitized = FieldSyncService::sanitizeConditional($config, $valid, 'b');
        $this->assertSame([['field' => 'a']], $sanitized['conditional']['rules']);

        $reported = FieldSyncService::prunedRuleReferences([
            ['handle' => 'a', 'config' => []],
            ['handle' => 'b', 'config' => $config],
        ]);
        $this->assertSame(['b' => ['deleted']], $reported);
    }

    // =========================================================================
    // Wiring (source guards)
    // =========================================================================

    public function testSaveNoticeWarnsAboutPrunedRules(): void
    {
        $code = $this->source('controllers/FormsController.php');
        $this->assertStringContainsString('FieldSyncService::prunedRuleReferences($items)', $code);
        $this->assertStringContainsString('were removed', $code);
    }

    public function testBuilderDeleteIsConfirmedAndWarnsAboutReferences(): void
    {
        $js = $this->source('web/assets/cp/dist/js/form-builder.js');

        // The canvas × goes through the confirm wrapper, not straight removal.
        $this->assertStringContainsString('confirmRemoveField(del.closest', $js);
        $this->assertStringContainsString('function fieldReferences(', $js);
        // Uses the shared accessible dialog (native confirm() is banned in CP JS).
        $this->assertStringContainsString('window.SimpleFormCp.sfConfirm', $js);
        $this->assertStringNotContainsString('window.confirm(', $js);

        // cp.js exposes the shared accessible dialog.
        $cp = $this->source('web/assets/cp/dist/js/cp.js');
        $this->assertStringContainsString('window.SimpleFormCp.sfConfirm = sfConfirm', $cp);
    }
}

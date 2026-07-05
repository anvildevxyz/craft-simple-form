<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * #292 — a multi-page form's canvas must show the step grouping instead of
 * leaving the author to track per-field "Step / Page" numbers mentally. The
 * runtime compacts page-number gaps (FormSteps::group), so the separator shows
 * the EFFECTIVE step and flags non-contiguous numbering.
 */
class BuilderPageSeparatorsTest extends TestCase
{
    public function testCanvasRendersStepSeparators(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../../src/web/assets/cp/dist/js/form-builder.js');

        // pageOf mirrors the server default (1-based, min 1).
        $this->assertStringContainsString('function pageOf(f)', $js);
        // Separators render per page change, only on multi-step forms, and are
        // cleared on re-render alongside the cards.
        $this->assertStringContainsString("sep.className = 'sf-page-sep'", $js);
        $this->assertStringContainsString('.sf-field, .sf-builder-row, .sf-page-sep', $js);
        $this->assertStringContainsString('pages.length > 1', $js);
        // Gap flagging: the label warns when the shown ordinal differs from
        // the authored page number.
        $this->assertStringContainsString('steps renumber contiguously', $js);
    }

    public function testSeparatorsAreStyled(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../src/web/assets/cp/dist/css/cp.css');

        $this->assertStringContainsString('.sf-page-sep', $css);
    }
}

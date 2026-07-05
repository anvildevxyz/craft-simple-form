<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * #291 — reordering was HTML5-drag only: no keyboard path, and drag events
 * never fire on iOS/Android, so tablet authors couldn't reorder at all. The
 * builder must offer per-card move buttons and a keyboard binding, with focus
 * and a live-region announcement preserved across the re-render.
 */
class BuilderReorderTest extends TestCase
{
    private function builderJs(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/web/assets/cp/dist/js/form-builder.js');
    }

    public function testMoveControlsExist(): void
    {
        $js = $this->builderJs();

        $this->assertStringContainsString('function moveField(', $js);
        // Per-card buttons, accessible names, end-of-list disabling.
        $this->assertStringContainsString("className = 'sf-field-move'", $js);
        $this->assertStringContainsString("setAttribute('aria-label', 'Move up')", $js);
        $this->assertStringContainsString("setAttribute('aria-label', 'Move down')", $js);
        $this->assertStringContainsString('up.disabled = idx <= 0', $js);
        // Canvas routes button clicks to the move, not card selection.
        $this->assertStringContainsString(".closest('.sf-field-move')", $js);
    }

    public function testKeyboardReorderBinding(): void
    {
        $js = $this->builderJs();

        $this->assertStringContainsString("(e.altKey || e.ctrlKey) && (e.key === 'ArrowUp' || e.key === 'ArrowDown')", $js);
    }

    public function testMoveAnnouncesAndRestoresFocus(): void
    {
        $js = $this->builderJs();

        $this->assertStringContainsString("announce('Moved to position '", $js);
        // render() rebuilds the DOM — the moved card must be refocused.
        $this->assertMatchesRegularExpression(
            '/function moveField\([\s\S]{0,900}querySelector\(\'\.sf-field\[data-cid="\' \+ cid \+ \'"\]\'\)/',
            $js,
        );
    }

    public function testMoveButtonsAreStyled(): void
    {
        $css = (string) file_get_contents(__DIR__ . '/../../src/web/assets/cp/dist/css/cp.css');

        $this->assertStringContainsString('.sf-field-move', $css);
        $this->assertStringContainsString('.sf-field-move:disabled', $css);
    }
}

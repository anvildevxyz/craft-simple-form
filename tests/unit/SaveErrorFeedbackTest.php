<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * #289 — a failed form save must tell the whole story: every validation error
 * flashed (not one per round-trip), and element errors — whose inline messages
 * render on the Details pane — must re-open that pane selected and badged
 * instead of leaving the author on a seemingly clean Build tab.
 */
class SaveErrorFeedbackTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/' . $relativePath);
    }

    public function testAllValidationErrorsAreFlashed(): void
    {
        $code = $this->source('controllers/FormsController.php');

        $this->assertStringNotContainsString('setError(reset($fieldErrors))', $code);
        $this->assertStringNotContainsString('setError(reset($messageErrors))', $code);
        $this->assertStringContainsString("implode(' ', \$fieldErrors)", $code);
        $this->assertStringContainsString("implode(' ', \$messageErrors)", $code);
    }

    public function testElementErrorFlashNamesTheErrors(): void
    {
        $code = $this->source('controllers/FormsController.php');

        $this->assertStringContainsString('$form->getFirstErrors()', $code);
        $this->assertStringContainsString("implode(' ', \$firstErrors)", $code);
    }

    public function testDetailsTabOpensBadgedOnElementErrors(): void
    {
        $code = $this->source('templates/forms/edit.twig');

        $this->assertStringContainsString('set detailsHasErrors = form.hasErrors()', $code);
        // Tab strip: badge + selection follow the errors.
        $this->assertStringContainsString("class: detailsHasErrors ? 'error' : null", $code);
        $this->assertStringContainsString("set selectedTab = detailsHasErrors ? 'sf-details' : 'sf-build'", $code);
        // Initial pane visibility must match the selected tab, or the badge
        // points at a pane that isn't showing.
        $this->assertStringContainsString('<div id="sf-build"{% if detailsHasErrors %} class="hidden"{% endif %}>', $code);
        $this->assertStringContainsString('<div id="sf-details"{% if not detailsHasErrors %} class="hidden"{% endif %}>', $code);
    }
}

<?php

namespace fabianhaef\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Guards that a Form element can never be saved without its shared
 * simpleform_forms row (which would orphan it: FormQuery inner-joins that table).
 * Source-level so it runs without a bootstrapped Craft application.
 */
class FormOrphanGuardTest extends TestCase
{
    private function afterSaveBody(): string
    {
        $code = (string) file_get_contents(__DIR__ . '/../../src/elements/Form.php');
        $start = strpos($code, 'function afterSave');
        $this->assertNotFalse($start, 'Form::afterSave should exist');
        // Window must span the whole shared-row block (the $shared map grows as new
        // form columns are added), so it still captures the canonical-only update
        // guard that follows the ungated insert.
        return substr($code, $start, 3000);
    }

    public function testSharedRowIsSeededOnAnySaveNotOnlyCanonical(): void
    {
        $body = $this->afterSaveBody();

        // The shared-row INSERT must run for any save (seed-if-missing), while
        // only the canonical save UPDATEs an existing row. The update being
        // behind `elseif (!$this->propagating)` proves the insert is ungated.
        $this->assertStringContainsString('elseif (!$this->propagating)', $body, 'Shared-row update must be canonical-only via elseif, leaving the insert ungated');
        $this->assertStringContainsString("insert('{{%simpleform_forms}}'", $body);

        // Regression guard: the insert must not be wrapped by a propagating gate.
        $this->assertStringNotContainsString(
            "if (!\$this->propagating) {\n            \$shared",
            $body,
            'The shared-row block must not be gated behind a propagating check'
        );
    }
}

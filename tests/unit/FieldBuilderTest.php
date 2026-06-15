<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\services\FieldSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source/structure guards for the batch-saved field builder. These stay at the
 * source level (like the other unit tests here) so they run without a
 * bootstrapped Craft application.
 */
class FieldBuilderTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/' . $relativePath);
    }

    public function testSyncServiceExposesValidateAndSync(): void
    {
        $ref = new ReflectionClass(FieldSyncService::class);
        $this->assertTrue($ref->hasMethod('validate'));
        $this->assertTrue($ref->hasMethod('sync'));

        $types = $ref->getConstant('VALID_TYPES');
        $this->assertIsArray($types);
        foreach (['text', 'email', 'textarea', 'select', 'checkbox', 'radio', 'date', 'number'] as $t) {
            $this->assertContains($t, $types);
        }
    }

    public function testSyncIsTransactionalAndInvalidatesCache(): void
    {
        $code = $this->source('services/FieldSyncService.php');

        $this->assertStringContainsString('beginTransaction', $code);
        $this->assertStringContainsString('commit', $code);
        $this->assertStringContainsString('rollBack', $code);
        // Removed fields are deleted (their _sites rows cascade via FK).
        $this->assertStringContainsString('array_diff', $code);
        // The structure cache must be invalidated after the field set changes.
        $this->assertStringContainsString('invalidate', $code);
        // config must be passed as an array (Craft's json column encodes once).
        $this->assertStringNotContainsString("json_encode(\$config", $code);
    }

    public function testSaveActionBatchesFieldsAndValidatesFirst(): void
    {
        $code = $this->source('controllers/FormsController.php');

        $this->assertStringContainsString('FieldSyncService', $code);
        $this->assertStringContainsString('parseFieldsData', $code);
        // Fields are validated before the element is saved.
        $validatePos = strpos($code, '->validate($items)');
        $savePos = strpos($code, 'saveElement($form)');
        $this->assertNotFalse($validatePos);
        $this->assertNotFalse($savePos);
        $this->assertLessThan($savePos, $validatePos, 'Field validation must run before saveElement');
    }

    public function testEditTemplateUsesBuilderNotLegacyModal(): void
    {
        $code = $this->source('templates/forms/edit.html');

        // New builder surface.
        $this->assertStringContainsString('id="sf-canvas"', $code);
        $this->assertStringContainsString('id="sf-palette"', $code);
        $this->assertStringContainsString('id="sf-inspector"', $code);
        $this->assertStringContainsString('name="fieldsData"', $code);

        // Legacy per-field modal/AJAX UI is gone.
        $this->assertStringNotContainsString('field-modal-overlay', $code);
        $this->assertStringNotContainsString('new-field-btn', $code);
    }
}

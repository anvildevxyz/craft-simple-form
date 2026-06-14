<?php

namespace fabianhaef\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Source-level assertions on the propagation trait and Form wiring. The live
 * getSupportedSites() behavior (which needs Craft's sites service) is exercised
 * by the multi-site DB verification flow rather than here.
 */
class HasPropagationTest extends TestCase
{
    public function testTraitExists(): void
    {
        $this->assertTrue(trait_exists(\fabianhaef\simpleform\traits\HasPropagation::class));
    }

    public function testTraitCoversAllPropagationMethods(): void
    {
        $code = file_get_contents(__DIR__ . '/../../src/traits/HasPropagation.php');
        foreach (['All', 'SiteGroup', 'Language'] as $case) {
            $this->assertStringContainsString("PropagationMethod::$case", $code);
        }
        // None is the default arm
        $this->assertStringContainsString('PropagationMethod::None', $code);
        $this->assertStringContainsString('getSupportedSites', $code);
    }

    public function testFormUsesTrait(): void
    {
        $code = file_get_contents(__DIR__ . '/../../src/elements/Form.php');
        $this->assertStringContainsString('use HasPropagation;', $code);
    }

    public function testFormAfterSaveGuardsSharedWriteButNotPerSiteWrite(): void
    {
        $code = file_get_contents(__DIR__ . '/../../src/elements/Form.php');
        // Shared write guarded by !propagating
        $this->assertStringContainsString('if (!$this->propagating)', $code);
        // Per-site upsert into the _sites table
        $this->assertStringContainsString('{{%simpleform_forms_sites}}', $code);
        $this->assertStringContainsString('upsert', $code);
    }
}

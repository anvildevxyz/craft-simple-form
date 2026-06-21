<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\integrations\CraftElementIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Pure-logic coverage for the element connector's mapping normalisation — the
 * bridge between the editable-table list shape posted by the settings UI and the
 * `sourceHandle => targetHandle` map the connector consumes.
 */
class CraftElementMappingTest extends TestCase
{
    public function testNormalisesEditableTableListShape(): void
    {
        $mapping = [
            ['source' => 'name', 'target' => 'title'],
            ['source' => 'email', 'target' => 'email'],
        ];

        $this->assertSame(
            ['name' => 'title', 'email' => 'email'],
            CraftElementIntegration::normalizeMapping($mapping),
        );
    }

    public function testNormalisesAssociativeShape(): void
    {
        $this->assertSame(
            ['name' => 'title'],
            CraftElementIntegration::normalizeMapping(['name' => 'title']),
        );
    }

    public function testDropsEmptySourceOrTarget(): void
    {
        $mapping = [
            ['source' => 'name', 'target' => ''],
            ['source' => '', 'target' => 'title'],
            ['source' => ' phone ', 'target' => ' phoneField '],
        ];

        $this->assertSame(
            ['phone' => 'phoneField'],
            CraftElementIntegration::normalizeMapping($mapping),
        );
    }

    public function testNonArrayYieldsEmpty(): void
    {
        $this->assertSame([], CraftElementIntegration::normalizeMapping('nope'));
        $this->assertSame([], CraftElementIntegration::normalizeMapping(null));
    }

    public function testRegisteredHandleAndName(): void
    {
        $this->assertSame('craft-element', CraftElementIntegration::handle());
        $this->assertSame('Create Craft Element', CraftElementIntegration::displayName());
    }
}

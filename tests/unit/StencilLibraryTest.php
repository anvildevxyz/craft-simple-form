<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\stencils\Stencil;
use PHPUnit\Framework\TestCase;

/**
 * Pure structural checks for the stencil data model — field-list shape and the
 * sync-item contract — without bootstrapping Craft. DB-backed instantiation and
 * the RegisterStencilsEvent path are covered by the integration suite.
 */
class StencilLibraryTest extends TestCase
{
    public function testStencilHydratesFromConfig(): void
    {
        $stencil = new Stencil([
            'handle' => 'feedback',
            'name' => 'Feedback',
            'description' => 'A short feedback form.',
            'fields' => [
                ['type' => 'text', 'handle' => 'name', 'label' => 'Name', 'required' => true],
            ],
            'notifications' => [
                ['name' => 'Alert', 'recipient' => 'ops@example.test'],
            ],
        ]);

        $this->assertSame('feedback', $stencil->handle);
        $this->assertSame('Feedback', $stencil->name);
        $this->assertCount(1, $stencil->fields);
        $this->assertSame('name', $stencil->fields[0]['handle']);
        $this->assertCount(1, $stencil->notifications);
    }

    public function testStencilIgnoresUnknownConfigKeys(): void
    {
        $stencil = new Stencil(['handle' => 'x', 'bogus' => 'ignored']);

        $this->assertSame('x', $stencil->handle);
        $this->assertFalse(property_exists($stencil, 'bogus'));
    }

    public function testEmptyStencilHasSaneDefaults(): void
    {
        $stencil = new Stencil();

        $this->assertSame('', $stencil->handle);
        $this->assertSame([], $stencil->fields);
        $this->assertSame([], $stencil->notifications);
    }
}

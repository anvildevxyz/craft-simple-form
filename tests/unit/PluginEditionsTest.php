<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\Editions;
use anvildev\simpleform\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * The plugin ships two commercial editions: Solo and Pro. Order matters —
 * `Plugin::is()` compares by index, so the lower tier (Solo) must come first.
 */
class PluginEditionsTest extends TestCase
{
    public function testDeclaresSoloAndStandardEditions(): void
    {
        $this->assertSame('solo', Plugin::EDITION_SOLO);
        $this->assertSame('standard', Plugin::EDITION_STANDARD);
        $this->assertSame([Plugin::EDITION_SOLO, Plugin::EDITION_STANDARD], Plugin::editions());
    }

    public function testEditionConstantsTrackTheEditionsHelper(): void
    {
        $this->assertSame(Editions::SOLO, Plugin::EDITION_SOLO);
        $this->assertSame(Editions::STANDARD, Plugin::EDITION_STANDARD);
    }
}

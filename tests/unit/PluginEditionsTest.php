<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\Plugin;
use PHPUnit\Framework\TestCase;

/**
 * The plugin ships a single commercial Pro edition (#117).
 */
class PluginEditionsTest extends TestCase
{
    public function testDeclaresSingleProEdition(): void
    {
        $this->assertSame('pro', Plugin::EDITION_PRO);
        $this->assertSame([Plugin::EDITION_PRO], Plugin::editions());
    }
}

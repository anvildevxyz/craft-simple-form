<?php

namespace anvildev\simpleform\tests\unit\mcp;

use PHPUnit\Framework\TestCase;

class CraftToolsTest extends TestCase
{
    public function testCraftToolsDelegateAndDoNotReplaceTheTokenServer(): void
    {
        $root = dirname(__DIR__, 3);
        $tools = $this->source($root . '/src/mcp/CraftTools.php');
        $plugin = $this->source($root . '/src/Plugin.php');
        $server = $this->source($root . '/src/mcp/McpServer.php');
        $controller = $this->source($root . '/src/controllers/McpController.php');

        $this->assertStringContainsString('class_exists(\\craft\\services\\Mcp::class)', $plugin);
        $this->assertStringContainsString('CraftTools::class', $plugin);
        $this->assertStringContainsString('class McpServer', $server);
        $this->assertStringContainsString('new McpServer()', $controller);
        $this->assertSame(17, preg_match_all("/name: 'simple_form_/", $tools));
        $this->assertSame(17, substr_count($tools, '$this->run('));
        $this->assertSame(10, substr_count($tools, 'readOnlyHint: true'));
        $this->assertSame(7, substr_count($tools, 'destructiveHint: true'));
        $this->assertStringNotContainsString('PublicMcp', $tools);
    }

    private function source(string $path): string
    {
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new \RuntimeException("Could not read {$path}");
        }

        return $source;
    }
}

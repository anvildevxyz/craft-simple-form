<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\mcp\tools\support\FormPresenter;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the parts of {@see FormPresenter} that don't touch the
 * element layer — the shared `id`/`handle` inputSchema selector (#167).
 * Resolver behaviour (found-by-id, found-by-handle, not-found) is covered by
 * {@see \fabianhaef\simpleform\tests\integration\McpFormToolsTest} where a Craft
 * app and DB are available.
 */
class FormPresenterTest extends TestCase
{
    public function testIdOrHandlePropertiesShape(): void
    {
        $properties = FormPresenter::idOrHandleProperties();

        $this->assertSame(['id', 'handle'], array_keys($properties));
        $this->assertSame('integer', $properties['id']['type']);
        $this->assertSame('string', $properties['handle']['type']);
        $this->assertArrayHasKey('description', $properties['id']);
        $this->assertArrayHasKey('description', $properties['handle']);
    }

    public function testIdOrHandlePropertiesMatchTheToolSchemas(): void
    {
        // Byte-for-byte the pair the form tools previously inlined; a drift here
        // would silently change the advertised inputSchema.
        $this->assertSame([
            'id' => ['type' => 'integer', 'description' => 'The form id. Provide id OR handle.'],
            'handle' => ['type' => 'string', 'description' => 'The form handle. Provide id OR handle.'],
        ], FormPresenter::idOrHandleProperties());
    }
}

<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\mcp\resources\AbstractFormResource;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the DB-free shared plumbing of {@see AbstractFormResource}
 * (#169): scheme-based {@code handles()} matching. The element-layer paths
 * ({@code list()}, {@code read()}, {@code resolveForm()}) and the
 * {@code contents()} envelope are covered by
 * {@see \fabianhaef\simpleform\tests\integration\McpResourcesTest} where a Craft
 * app and DB are available.
 */
class AbstractFormResourceTest extends TestCase
{
    public function testHandlesMatchesOwnSchemeOnly(): void
    {
        $resource = new class extends AbstractFormResource {
            public function requiredScope(): string
            {
                return 'noop';
            }

            protected function scheme(): string
            {
                return 'thing';
            }

            protected function mimeType(): string
            {
                return 'application/json';
            }

            /**
             * @return array<string, mixed>
             */
            protected function describe(Form $form): array
            {
                return [];
            }

            /**
             * @return array<string, mixed>
             */
            protected function payload(Form $form): array
            {
                return [];
            }
        };

        $this->assertTrue($resource->handles('thing://contactForm'));
        $this->assertTrue($resource->handles('thing://'));
        $this->assertFalse($resource->handles('other://contactForm'));
        $this->assertFalse($resource->handles('thing:contactForm'));
        $this->assertFalse($resource->handles('contactForm'));
    }
}

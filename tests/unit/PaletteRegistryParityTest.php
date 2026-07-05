<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\services\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * #281 — every built-in field type registered in {@see FieldTypeRegistry} must
 * have a palette entry in the form-builder template and a label in the builder
 * JS, so the registry and the CP authoring surface can't drift apart again
 * (the signature field shipped registry-only and was unreachable from the UI).
 *
 * Source-level like the other unit tests here: the registry's built-in
 * registration runs without a bootstrapped Craft application (the third-party
 * registration event is guarded on the app), and the template/JS are asserted
 * as text.
 */
class PaletteRegistryParityTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/' . $relativePath);
    }

    /**
     * @return string[]
     */
    private function registeredHandles(): array
    {
        $registry = new FieldTypeRegistry();
        $registry->init();

        return $registry->typeHandles();
    }

    public function testEveryRegisteredTypeHasAPaletteEntry(): void
    {
        $template = $this->source('templates/forms/edit.twig');

        foreach ($this->registeredHandles() as $handle) {
            $this->assertStringContainsString(
                "type: '$handle'",
                $template,
                "Field type `$handle` is registered but has no palette entry in forms/edit.twig.",
            );
        }
    }

    public function testEveryRegisteredTypeHasABuilderLabel(): void
    {
        $js = $this->source('web/assets/cp/dist/js/form-builder.js');
        preg_match('/var TYPE_LABELS = \{(.*?)\};/s', $js, $match);
        $this->assertNotEmpty($match, 'TYPE_LABELS map not found in form-builder.js');

        foreach ($this->registeredHandles() as $handle) {
            $this->assertMatchesRegularExpression(
                "/\\b$handle: '/",
                $match[1],
                "Field type `$handle` is registered but has no TYPE_LABELS entry in form-builder.js.",
            );
        }
    }
}

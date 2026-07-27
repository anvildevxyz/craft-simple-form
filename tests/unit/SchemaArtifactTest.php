<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * #224 — guards the committed developer artifacts against drift:
 *  - docs/reference/schema.graphql must declare every SimpleForm* GraphQL type
 *    the plugin registers, plus the public queries/mutations;
 *  - .phpstorm.meta.php must be present and well-formed.
 *
 * Pure (no Craft container): reads the gql type classes' static getName() and
 * checks the SDL text, so adding a new GraphQL type fails this test until the
 * SDL is regenerated.
 */
class SchemaArtifactTest extends TestCase
{
    private function pluginRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public function testSdlExistsAndCoversOperations(): void
    {
        $sdl = (string) file_get_contents($this->pluginRoot() . '/docs/reference/schema.graphql');
        $this->assertNotSame('', $sdl, 'schema.graphql should exist and be non-empty');

        foreach (['simpleForm(', 'simpleForms(', 'submitForm(', 'updateSubmission('] as $op) {
            $this->assertStringContainsString($op, $sdl, "SDL should document the $op operation");
        }
    }

    public function testSdlCoversEveryRegisteredSimpleFormType(): void
    {
        $sdl = (string) file_get_contents($this->pluginRoot() . '/docs/reference/schema.graphql');

        $checked = 0;
        foreach (glob($this->pluginRoot() . '/src/gql/types/*.php') ?: [] as $file) {
            $class = 'anvildev\\simpleform\\gql\\types\\' . basename($file, '.php');
            if (!class_exists($class) || !method_exists($class, 'getName')) {
                continue;
            }
            if ((new \ReflectionClass($class))->isAbstract()) {
                continue;
            }
            $name = (string) $class::getName();
            // Only the public, named SimpleForm* types belong in the SDL.
            if (!str_starts_with($name, 'SimpleForm')) {
                continue;
            }
            $this->assertMatchesRegularExpression(
                '/\b(type|input|enum) ' . preg_quote($name, '/') . '\b/',
                $sdl,
                "SDL is missing GraphQL type $name (regenerate docs/reference/schema.graphql)",
            );
            $checked++;
        }

        $this->assertGreaterThanOrEqual(8, $checked, 'Expected to verify the SimpleForm GraphQL types');
    }

    public function testPhpStormMetaIsPresentAndWellFormed(): void
    {
        $path = $this->pluginRoot() . '/.phpstorm.meta.php';
        $this->assertFileExists($path);
        $meta = (string) file_get_contents($path);
        $this->assertStringContainsString('namespace PHPSTORM_META', $meta);

        // Lint without executing (the PHPSTORM_META helpers aren't real functions).
        $output = [];
        $status = 0;
        exec('php -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
        $this->assertSame(0, $status, 'phpstorm meta should be valid PHP: ' . implode("\n", $output));
    }
}

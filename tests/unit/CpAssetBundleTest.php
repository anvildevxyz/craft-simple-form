<?php

namespace fabianhaef\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * #100 — the consolidated CP asset bundle must reference dist files that
 * actually exist, and the CP templates must no longer carry inline
 * <style>/<script>/{% js %} blocks. Pure file-system asserts, no Craft boot.
 */
class CpAssetBundleTest extends TestCase
{
    private const DIST = __DIR__ . '/../../src/web/assets/cp/dist';
    private const TEMPLATES = __DIR__ . '/../../src/templates';

    public function testBundleDistFilesExist(): void
    {
        foreach (['css/cp.css', 'js/cp.js', 'js/form-builder.js'] as $rel) {
            $this->assertFileExists(self::DIST . '/' . $rel, "Missing bundle asset: $rel");
        }
    }

    public function testExtractedBuilderJsHasNoTwig(): void
    {
        $js = (string) file_get_contents(self::DIST . '/js/form-builder.js');
        $this->assertStringNotContainsString('{{', $js, 'form-builder.js still contains Twig output tags');
        $this->assertStringNotContainsString('{%', $js, 'form-builder.js still contains Twig statement tags');
        // It must read its dynamic config from the .sf-builder data attributes.
        $this->assertStringContainsString('sfData.sourceSite', $js);
        $this->assertStringContainsString('sfData.volumes', $js);
        $this->assertStringContainsString('sfData.initialFields', $js);
    }

    public function testNoInlineStyleOrScriptInCpTemplates(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::TEMPLATES, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->getExtension() !== 'html') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/<style>|<script>|\{%\s*js\s*%\}/', $contents)) {
                $offenders[] = $file->getPathname();
            }
        }
        $this->assertSame([], $offenders, 'Inline asset blocks remain in: ' . implode(', ', $offenders));
    }
}

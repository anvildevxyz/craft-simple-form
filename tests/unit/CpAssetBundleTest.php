<?php

namespace anvildev\simpleform\tests\unit;

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

    public function testNoNativeDialogCallsInCpJs(): void
    {
        foreach (['js/cp.js', 'js/form-builder.js'] as $rel) {
            $js = (string) file_get_contents(self::DIST . '/' . $rel);
            // Strip block + line comments so prose mentioning confirm()/alert()
            // in docblocks doesn't trip the check (#103).
            $code = (string) preg_replace('!/\*.*?\*/!s', '', $js);
            $code = (string) preg_replace('!//.*$!m', '', $code);
            $this->assertStringNotContainsString('alert(', $code, "$rel uses native alert()");
            // sfConfirm() keeps its capital C, so a lowercase match means a
            // native confirm()/window.confirm() crept back in.
            $this->assertStringNotContainsString('confirm(', $code, "$rel uses native confirm()");
        }

        $cp = (string) file_get_contents(self::DIST . '/js/cp.js');
        $this->assertStringContainsString('function sfConfirm', $cp, 'cp.js provides the dialog-based confirm');
        $this->assertStringContainsString("createElement('dialog')", $cp);
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

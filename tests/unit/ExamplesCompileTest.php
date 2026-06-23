<?php

namespace fabianhaef\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * #221 — guards the copy-paste examples/ so they never rot: every example PHP
 * file must be syntactically valid (php -l), and the expected set of examples
 * must be present.
 */
class ExamplesCompileTest extends TestCase
{
    private function examplesDir(): string
    {
        return dirname(__DIR__, 2) . '/examples';
    }

    public function testExpectedExamplesExist(): void
    {
        $dir = $this->examplesDir();
        foreach ([
            'README.md',
            'fieldtype/ColorField.php',
            'integration/JsonWebhookIntegration.php',
            'captcha/MathCaptchaProvider.php',
            'theme/field.twig',
        ] as $rel) {
            $this->assertFileExists($dir . '/' . $rel);
        }
    }

    public function testEveryExamplePhpFileCompiles(): void
    {
        $files = $this->phpFiles($this->examplesDir());
        $this->assertNotEmpty($files, 'Expected example PHP files');

        foreach ($files as $file) {
            $out = [];
            $status = 0;
            exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $status);
            $this->assertSame(0, $status, "Example should be valid PHP ($file): " . implode("\n", $out));
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $found[] = $file->getPathname();
            }
        }
        return $found;
    }
}

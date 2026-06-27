<?php

namespace anvildev\simpleform\tests\unit;

use PHPUnit\Framework\TestCase;

class MultiSiteTranslationTest extends TestCase
{
    private function getFormClassCode(): string
    {
        $file = __DIR__ . '/../../src/elements/Form.php';
        $code = file_get_contents($file);
        $this->assertNotFalse($code);
        return $code;
    }

    private function getSubmissionClassCode(): string
    {
        $file = __DIR__ . '/../../src/elements/Submission.php';
        $code = file_get_contents($file);
        $this->assertNotFalse($code);
        return $code;
    }

    public function testFormElementDefinesIsLocalized(): void
    {
        $code = $this->getFormClassCode();
        $this->assertStringContainsString('public static function isLocalized(): bool', $code);
        $this->assertStringContainsString('return true;', $code);
    }

    public function testFormElementDefinesHasContent(): void
    {
        $code = $this->getFormClassCode();
        $this->assertStringContainsString('public static function hasContent(): bool', $code);
        $this->assertStringContainsString('return true;', $code);
    }

    public function testFormElementDefinesHasTitles(): void
    {
        $code = $this->getFormClassCode();
        $this->assertStringContainsString('public static function hasTitles(): bool', $code);
        $this->assertStringContainsString('return true;', $code);
    }

    public function testFormElementHasTranslatableFields(): void
    {
        $code = $this->getFormClassCode();
        // These fields are translatable when hasContent() and isLocalized() return true
        $this->assertStringContainsString('public ?string $title = null;', $code);
        $this->assertStringContainsString('public ?string $description = null;', $code);
    }

    public function testSubmissionElementDefinesIsLocalized(): void
    {
        $code = $this->getSubmissionClassCode();
        $this->assertStringContainsString('public static function isLocalized(): bool', $code);
        $this->assertStringContainsString('return true;', $code);
    }

    public function testSubmissionElementSupportsMultipleSites(): void
    {
        $code = $this->getSubmissionClassCode();
        // Submissions are localized and inherit siteId from Element
        $this->assertStringContainsString('public static function isLocalized(): bool', $code);
    }

    public function testFormElementExtendsElement(): void
    {
        $code = $this->getFormClassCode();
        $this->assertStringContainsString('extends Element', $code);
    }

    public function testSubmissionElementExtendsElement(): void
    {
        $code = $this->getSubmissionClassCode();
        $this->assertStringContainsString('extends Element', $code);
    }

    public function testFormAndSubmissionBothLocalized(): void
    {
        $formCode = $this->getFormClassCode();
        $submissionCode = $this->getSubmissionClassCode();

        // Both elements return true for isLocalized()
        $this->assertStringContainsString('public static function isLocalized(): bool', $formCode);
        $this->assertStringContainsString('return true;', $formCode);

        $this->assertStringContainsString('public static function isLocalized(): bool', $submissionCode);
        $this->assertStringContainsString('return true;', $submissionCode);
    }
}

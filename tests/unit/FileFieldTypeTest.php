<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\fields\FileFieldType;
use PHPUnit\Framework\TestCase;

class FileFieldTypeTest extends TestCase
{
    public function testTypeAndLabel(): void
    {
        $this->assertSame('file', FileFieldType::getType());
        $this->assertSame('File Upload', FileFieldType::getLabel());
    }

    public function testAllowedExtensionsParsing(): void
    {
        $this->assertSame(['pdf', 'jpg', 'png'], (new FileFieldType(['allowedExtensions' => 'pdf, .JPG  png']))->allowedExtensions());
        $this->assertSame(['pdf'], (new FileFieldType(['allowedExtensions' => ['.PDF']]))->allowedExtensions());
        $this->assertSame([], (new FileFieldType(['allowedExtensions' => '']))->allowedExtensions());
        $this->assertSame([], (new FileFieldType([]))->allowedExtensions());
    }

    public function testMaxBytes(): void
    {
        $this->assertSame(2 * 1024 * 1024, (new FileFieldType(['maxSize' => 2]))->maxBytes());
        $this->assertNull((new FileFieldType([]))->maxBytes());
        $this->assertNull((new FileFieldType(['maxSize' => 0]))->maxBytes());
    }

    public function testRenderInputSingle(): void
    {
        $html = (new FileFieldType(['allowedExtensions' => 'pdf,jpg', 'required' => true]))->renderInput('field_5');
        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString('name="field_5"', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('accept=".pdf,.jpg"', $html);
        $this->assertStringNotContainsString('multiple', $html);
    }

    public function testRenderInputMultiple(): void
    {
        $html = (new FileFieldType(['multiple' => true]))->renderInput('field_5');
        $this->assertStringContainsString('name="field_5[]"', $html);
        $this->assertStringContainsString('multiple', $html);
    }
}

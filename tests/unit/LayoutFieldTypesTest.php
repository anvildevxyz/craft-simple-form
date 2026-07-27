<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\fields\CalloutFieldType;
use anvildev\simpleform\fields\DividerFieldType;
use anvildev\simpleform\fields\HeadingFieldType;
use anvildev\simpleform\fields\HtmlFieldType;
use anvildev\simpleform\fields\ParagraphFieldType;
use anvildev\simpleform\fields\TextFieldType;
use anvildev\simpleform\services\FieldTypeRegistry;
use PHPUnit\Framework\TestCase;

/**
 * #127 — the value-less presentational blocks. Pure render-string / escaping /
 * purifier asserts, no Craft boot. The forced-sandbox Twig render of the HTML
 * block (and its end-to-end skip from submission/export) is exercised in the
 * integration suite, which boots a real Craft.
 */
class LayoutFieldTypesTest extends TestCase
{
    // =========================================================================
    // isInput seam
    // =========================================================================

    public function testInputFieldsAreInputByDefault(): void
    {
        $this->assertTrue((new TextFieldType())->isInput());
    }

    public function testLayoutBlocksAreNotInput(): void
    {
        $this->assertFalse((new HeadingFieldType())->isInput());
        $this->assertFalse((new DividerFieldType())->isInput());
        $this->assertFalse((new HtmlFieldType())->isInput());
        $this->assertFalse((new ParagraphFieldType())->isInput());
        $this->assertFalse((new CalloutFieldType())->isInput());
    }

    public function testLayoutBlocksNeverValidate(): void
    {
        // Even a forged `required` flag against an empty value yields no errors.
        $this->assertSame([], (new HeadingFieldType(['required' => true]))->validate(''));
        $this->assertSame([], (new DividerFieldType(['required' => true]))->validate(null));
        $this->assertSame([], (new HtmlFieldType(['required' => true]))->validate(''));
        $this->assertSame([], (new ParagraphFieldType(['required' => true]))->validate(''));
        $this->assertSame([], (new CalloutFieldType(['required' => true]))->validate(''));
    }

    // =========================================================================
    // Registry seam
    // =========================================================================

    public function testRegistryClassifiesLayoutBlocks(): void
    {
        $layout = (new FieldTypeRegistry())->layoutTypeHandles();

        // The value-less presentational blocks — including the new paragraph
        // ("Text") element — are classified as layout, derived from isInput().
        $this->assertContains('heading', $layout);
        $this->assertContains('divider', $layout);
        $this->assertContains('html', $layout);
        $this->assertContains('paragraph', $layout);
        $this->assertContains('callout', $layout);

        // A value-collecting input is never a layout handle.
        $this->assertNotContains('text', $layout);
        $this->assertNotContains('email', $layout);
    }

    // =========================================================================
    // Heading
    // =========================================================================

    public function testHeadingRendersConfiguredLevelWithEscapedText(): void
    {
        $html = (new HeadingFieldType(['level' => 'h2', 'text' => 'Personal <b>details</b>']))->renderInput('field_1');
        $this->assertStringContainsString('<h2 class="simple-form-heading">', $html);
        $this->assertStringContainsString('Personal &lt;b&gt;details&lt;/b&gt;', $html);
        $this->assertStringContainsString('</h2>', $html);
        $this->assertStringNotContainsString('<b>details', $html);
    }

    public function testHeadingClampsInvalidLevelToDefault(): void
    {
        $this->assertSame('h3', (new HeadingFieldType(['level' => 'h1']))->level());
        $this->assertSame('h3', (new HeadingFieldType(['level' => 'script']))->level());
        $this->assertSame('h3', (new HeadingFieldType([]))->level());
        $this->assertSame('h4', (new HeadingFieldType(['level' => 'h4']))->level());

        // A forged level never reaches the markup.
        $html = (new HeadingFieldType(['level' => 'h1', 'text' => 'Hi']))->renderInput('field_1');
        $this->assertStringContainsString('<h3', $html);
        $this->assertStringNotContainsString('<h1', $html);
    }

    public function testHeadingWithEmptyTextRendersNothing(): void
    {
        $this->assertSame('', (new HeadingFieldType(['level' => 'h2', 'text' => '   ']))->renderInput('field_1'));
    }

    // =========================================================================
    // Divider
    // =========================================================================

    public function testDividerRendersPlainRuleWithoutLabel(): void
    {
        $this->assertSame('<hr class="simple-form-divider">', (new DividerFieldType([]))->renderInput('field_1'));
    }

    public function testDividerRendersEscapedLabel(): void
    {
        $html = (new DividerFieldType(['label' => 'Or & "more"']))->renderInput('field_1');
        $this->assertStringContainsString('<hr>', $html);
        $this->assertStringContainsString('simple-form-divider__label', $html);
        $this->assertStringContainsString('Or &amp; &quot;more&quot;', $html);
    }

    // =========================================================================
    // Paragraph ("Text" element)
    // =========================================================================

    public function testParagraphType(): void
    {
        $this->assertSame('paragraph', ParagraphFieldType::getType());
        $this->assertSame('Text', ParagraphFieldType::getLabel());
    }

    public function testParagraphPreservesLineBreaksWithEscapedText(): void
    {
        $html = (new ParagraphFieldType(['text' => "Line one\nLine two"]))->renderInput('field_1');
        $this->assertStringContainsString('<div class="simple-form-text">', $html);
        $this->assertStringContainsString('Line one', $html);
        $this->assertStringContainsString('Line two', $html);
        // A newline is preserved as a <br>.
        $this->assertMatchesRegularExpression('/Line one<br\s*\/?>\s*\n?Line two/', $html);
    }

    public function testParagraphEscapesMarkupInsteadOfExecutingIt(): void
    {
        // Security line vs. the HTML block: raw markup is rendered as literal
        // escaped text, never as executed HTML.
        $html = (new ParagraphFieldType([
            'text' => '<script>alert(1)</script> & <b>bold</b>',
        ]))->renderInput('field_1');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
    }

    public function testParagraphWithEmptyTextRendersNothing(): void
    {
        $this->assertSame('', (new ParagraphFieldType(['text' => '   ']))->renderInput('field_1'));
        $this->assertSame('', (new ParagraphFieldType([]))->renderInput('field_1'));
    }

    // =========================================================================
    // Callout
    // =========================================================================

    public function testCalloutType(): void
    {
        $this->assertSame('callout', CalloutFieldType::getType());
        $this->assertSame('Callout', CalloutFieldType::getLabel());
    }

    public function testCalloutRendersTonedPanelWithEscapedLineBreakBody(): void
    {
        $html = (new CalloutFieldType([
            'tone' => 'warning',
            'body' => "Heads up\n<b>read</b> this & that",
        ]))->renderInput('field_1');

        $this->assertStringContainsString('<div class="simple-form-callout simple-form-callout--warning" role="note">', $html);
        $this->assertStringContainsString('<div class="simple-form-callout__body">', $html);
        // Escaped, not executed; line break preserved as <br>.
        $this->assertStringNotContainsString('<b>read</b>', $html);
        $this->assertStringContainsString('&lt;b&gt;read&lt;/b&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertMatchesRegularExpression('/Heads up<br\s*\/?>\s*\n?/', $html);
    }

    public function testCalloutClampsForgedToneToDefault(): void
    {
        $this->assertSame('info', (new CalloutFieldType(['tone' => 'nope']))->tone());
        $this->assertSame('info', (new CalloutFieldType([]))->tone());
        $this->assertSame('error', (new CalloutFieldType(['tone' => 'error']))->tone());

        // A forged tone never reaches the class attribute.
        $html = (new CalloutFieldType(['tone' => '"><script>', 'body' => 'x']))->renderInput('field_1');
        $this->assertStringContainsString('simple-form-callout--info', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testCalloutRendersEscapedIcon(): void
    {
        $html = (new CalloutFieldType(['icon' => 'ℹ️<b>', 'body' => 'Hi']))->renderInput('field_1');
        $this->assertStringContainsString('<span class="simple-form-callout__icon" aria-hidden="true">', $html);
        $this->assertStringContainsString('ℹ️&lt;b&gt;', $html);
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testCalloutWithNoBodyOrIconRendersNothing(): void
    {
        $this->assertSame('', (new CalloutFieldType(['tone' => 'info', 'body' => '   ']))->renderInput('field_1'));
        $this->assertSame('', (new CalloutFieldType([]))->renderInput('field_1'));
    }

    // =========================================================================
    // HTML block allowlist constants
    // =========================================================================

    public function testHtmlAllowlistExcludesScriptVectors(): void
    {
        // The documented allowlist never names script/style/iframe tags or a
        // javascript:/data: scheme — the purifier pass (exercised in the
        // integration suite, which boots Yii) enforces it at runtime.
        $this->assertStringNotContainsString('script', HtmlFieldType::ALLOWED_TAGS);
        $this->assertStringNotContainsString('style', HtmlFieldType::ALLOWED_TAGS);
        $this->assertStringNotContainsString('iframe', HtmlFieldType::ALLOWED_TAGS);
        $this->assertArrayNotHasKey('javascript', HtmlFieldType::ALLOWED_URI_SCHEMES);
        $this->assertArrayNotHasKey('data', HtmlFieldType::ALLOWED_URI_SCHEMES);
        $this->assertArrayHasKey('https', HtmlFieldType::ALLOWED_URI_SCHEMES);
    }
}

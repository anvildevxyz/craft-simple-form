<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\helpers\ConsentText;
use PHPUnit\Framework\TestCase;

/**
 * #125 — the Consent field's rich label is rendered through a fixed, audited
 * allowlist transform: one safe http(s) `[label](url)` link, everything else
 * escaped. No arbitrary HTML/Twig, no XSS. Pure string asserts, no Craft boot.
 */
class ConsentTextTest extends TestCase
{
    public function testMarkdownLinkBecomesSafeAnchor(): void
    {
        $html = ConsentText::render('I agree to the [privacy policy](https://example.com/privacy)');

        $this->assertStringContainsString(
            '<a href="https://example.com/privacy" target="_blank" rel="noopener noreferrer">privacy policy</a>',
            $html,
        );
        $this->assertStringContainsString('I agree to the ', $html);
    }

    public function testJavascriptUrlIsRejectedAndFlattenedToText(): void
    {
        $html = ConsentText::render('Click [here](javascript:alert(1))');

        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('here', $html);
    }

    public function testDataUrlIsRejected(): void
    {
        $html = ConsentText::render('[x](data:text/html,<script>alert(1)</script>)');

        $this->assertStringNotContainsString('<a', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testSurroundingHtmlIsEscaped(): void
    {
        $html = ConsentText::render('<script>alert(1)</script> I agree');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testScriptInsideLinkLabelIsNeutralized(): void
    {
        $html = ConsentText::render('[<script>x</script>](https://example.com)');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<a href="https://example.com"', $html);
    }

    public function testEmptyTextRendersEmpty(): void
    {
        $this->assertSame('', ConsentText::render(''));
        $this->assertSame('', ConsentText::render('   '));
    }

    public function testPlainFlattensLinkToLabelAndUrl(): void
    {
        $plain = ConsentText::plain('I agree to the [privacy policy](https://example.com/privacy)');

        $this->assertSame('I agree to the privacy policy (https://example.com/privacy)', $plain);
        $this->assertStringNotContainsString('[', $plain);
    }

    public function testHashIsStableForIdenticalTextAndDiffersWhenEdited(): void
    {
        $a = ConsentText::hash('I agree to the [privacy policy](https://example.com/privacy)');
        $b = ConsentText::hash('I agree to the [privacy policy](https://example.com/privacy)');
        $c = ConsentText::hash('I agree to the [updated privacy policy](https://example.com/privacy)');

        $this->assertStringStartsWith('sha256:', $a);
        $this->assertSame($a, $b);
        $this->assertNotSame($a, $c);
    }
}

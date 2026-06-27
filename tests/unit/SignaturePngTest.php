<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\helpers\SignaturePng;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the signature PNG data-URL decode/validate guard (#129):
 * a real PNG decodes; mistyped, malformed, oversized, or non-PNG payloads are
 * rejected before any asset can be created.
 */
class SignaturePngTest extends TestCase
{
    /** A minimal valid 1×1 PNG as a base64 data URL. */
    private function validPngDataUrl(): string
    {
        // 1×1 transparent PNG (the standard reference pixel).
        $base64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC';
        return 'data:image/png;base64,' . $base64;
    }

    public function testHasDrawing(): void
    {
        $this->assertTrue(SignaturePng::hasDrawing($this->validPngDataUrl()));
        $this->assertTrue(SignaturePng::hasDrawing('data:image/png;base64,abc'));
        $this->assertFalse(SignaturePng::hasDrawing(''));
        $this->assertFalse(SignaturePng::hasDrawing('   '));
        $this->assertFalse(SignaturePng::hasDrawing(null));
        $this->assertFalse(SignaturePng::hasDrawing(['x']));
    }

    public function testDecodeValidPng(): void
    {
        $bytes = SignaturePng::decode($this->validPngDataUrl());
        $this->assertNotNull($bytes);
        $this->assertStringStartsWith("\x89PNG", (string) $bytes);
        $this->assertTrue(SignaturePng::isValid($this->validPngDataUrl()));
    }

    public function testRejectsNonString(): void
    {
        $this->assertNull(SignaturePng::decode(null));
        $this->assertNull(SignaturePng::decode(123));
        $this->assertNull(SignaturePng::decode(''));
    }

    public function testRejectsWrongMediaType(): void
    {
        // A valid base64 payload but declared as SVG/HTML must be rejected on the
        // declared media type — never decoded and sniffed.
        $svg = 'data:image/svg+xml;base64,' . base64_encode('<svg></svg>');
        $html = 'data:text/html;base64,' . base64_encode('<script>alert(1)</script>');
        $this->assertNull(SignaturePng::decode($svg));
        $this->assertNull(SignaturePng::decode($html));
        $this->assertFalse(SignaturePng::isValid($svg));
    }

    public function testRejectsMalformedDataUrl(): void
    {
        $this->assertNull(SignaturePng::decode('not-a-data-url'));
        $this->assertNull(SignaturePng::decode('data:image/png;base64,'));
        $this->assertNull(SignaturePng::decode('data:image/png;base64,!!!not base64!!!'));
    }

    public function testRejectsNonPngBytesWithPngMediaType(): void
    {
        // Correctly declared image/png, valid base64, but the decoded bytes are
        // not a PNG (no magic signature) → rejected by the magic-byte check.
        $fake = 'data:image/png;base64,' . base64_encode('this is plainly not a png');
        $this->assertNull(SignaturePng::decode($fake));
    }

    public function testRejectsOversizePayload(): void
    {
        $valid = $this->validPngDataUrl();
        // A 1-byte ceiling rejects even the tiny reference PNG.
        $this->assertNull(SignaturePng::decode($valid, 1));
        $this->assertFalse(SignaturePng::isValid($valid, 1));
        // The same payload passes under the default ceiling.
        $this->assertNotNull(SignaturePng::decode($valid));
    }
}

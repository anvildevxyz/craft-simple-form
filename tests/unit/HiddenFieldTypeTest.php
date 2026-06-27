<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\fields\HiddenFieldType;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the Hidden field type (#124). These cover the Craft-free
 * seams: the type identity, the non-input flag, and the bare-input markup
 * (no label/wrapper, value escaped). The server-side security re-resolve of
 * the `user` source and the localized validation message are exercised in the
 * integration/smoke suite, which boots a real Craft + authenticated user.
 */
class HiddenFieldTypeTest extends TestCase
{
    public function testTypeAndLabel(): void
    {
        $this->assertSame('hidden', HiddenFieldType::getType());
        $this->assertSame('Hidden', HiddenFieldType::getLabel());
    }

    public function testIsNotAVisibleInput(): void
    {
        // Hidden collects a stored value (isInput stays true) but never renders
        // inside the standard labelled field group.
        $this->assertTrue((new HiddenFieldType([]))->isInput());
        $this->assertFalse((new HiddenFieldType([]))->rendersInGroup());
    }

    public function testRenderInputEmitsBareHiddenInputWithNoLabel(): void
    {
        $html = (new HiddenFieldType(['source' => 'static', 'default' => 'spring-sale']))
            ->renderInput('field_77');

        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="field_77"', $html);
        $this->assertStringContainsString('id="field_77"', $html);
        $this->assertStringContainsString('value="spring-sale"', $html);
        $this->assertStringNotContainsString('<label', $html);
    }

    public function testRenderInputEscapesValue(): void
    {
        $html = (new HiddenFieldType(['source' => 'static', 'default' => '"><script>x']))
            ->renderInput('field_1');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRenderInputPrefersPassedValueOverConfigDefault(): void
    {
        // A resume-prefilled value wins over the configured default.
        $html = (new HiddenFieldType(['source' => 'static', 'default' => 'fallback']))
            ->renderInput('field_1', 'resumed');

        $this->assertStringContainsString('value="resumed"', $html);
    }

    public function testRenderInputClampsPassedValueToMaxLength(): void
    {
        $html = (new HiddenFieldType(['maxLength' => 4]))->renderInput('field_1', 'abcdefgh');
        $this->assertStringContainsString('value="abcd"', $html);
    }

    public function testResolveForSubmitPassesThroughSanitizedStaticAndQuery(): void
    {
        $static = new HiddenFieldType(['source' => 'static', 'maxLength' => 10]);
        $this->assertSame('hello', $static->resolveForSubmit('  hello  '));

        $query = new HiddenFieldType(['source' => 'query', 'maxLength' => 3]);
        $this->assertSame('abc', $query->resolveForSubmit('abcdef'));
    }
}

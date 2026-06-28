<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\fields\OpinionScaleFieldType;
use anvildev\simpleform\fields\RatingFieldType;
use PHPUnit\Framework\TestCase;

/**
 * #128 — bounds validation, integer storage, config clamping, and accessible
 * radio rendering for the Rating and Opinion Scale field types. Pure source
 * asserts (no Craft boot), mirroring the other field unit tests here.
 */
class RatingScaleFieldTypeTest extends TestCase
{
    // =========================================================================
    // Rating
    // =========================================================================

    public function testRatingDefaultsToOneToFiveStars(): void
    {
        $field = new RatingFieldType([]);
        $this->assertSame(5, $field->max());
        $this->assertSame('star', $field->iconStyle());
        $this->assertSame([1, 2, 3, 4, 5], $field->allowedValues());
        $this->assertTrue($field->isChoiceGroup());
    }

    public function testRatingMaxClampsToOneToTen(): void
    {
        $this->assertSame(10, (new RatingFieldType(['max' => 50]))->max());
        $this->assertSame(1, (new RatingFieldType(['max' => 0]))->max());
        $this->assertSame(1, (new RatingFieldType(['max' => -3]))->max());
        $this->assertSame(7, (new RatingFieldType(['max' => 7]))->max());
    }

    public function testRatingIconStyleFallsBackToStar(): void
    {
        $this->assertSame('heart', (new RatingFieldType(['iconStyle' => 'heart']))->iconStyle());
        $this->assertSame('number', (new RatingFieldType(['iconStyle' => 'number']))->iconStyle());
        $this->assertSame('star', (new RatingFieldType(['iconStyle' => 'bogus']))->iconStyle());
    }

    public function testRatingValidatesBounds(): void
    {
        $field = new RatingFieldType(['max' => 5]);
        $this->assertSame([], $field->validate(3));
        $this->assertSame([], $field->validate('5'));
        $this->assertNotEmpty($field->validate(6));
        $this->assertNotEmpty($field->validate(0));
        $this->assertNotEmpty($field->validate('x'));
        $this->assertNotEmpty($field->validate('3.5'));
    }

    public function testRatingRequiredEmptyFails(): void
    {
        $this->assertNotEmpty((new RatingFieldType(['required' => true]))->validate(''));
        $this->assertNotEmpty((new RatingFieldType(['required' => true]))->validate(null));
        // Optional + empty is allowed (no membership check on an empty value).
        $this->assertSame([], (new RatingFieldType([]))->validate(''));
    }

    public function testRatingNormalizesToInt(): void
    {
        $field = new RatingFieldType([]);
        $this->assertSame(4, $field->normalizeValue('4'));
        $this->assertSame(4, $field->normalizeValue(4));
        // Empty passes through untouched (stores nothing for an optional skip).
        $this->assertSame('', $field->normalizeValue(''));
        $this->assertNull($field->normalizeValue(null));
    }

    public function testRatingRendersAccessibleRadioGroup(): void
    {
        $html = (new RatingFieldType(['max' => 3]))->renderInput('field_7', 2);

        $this->assertSame(3, substr_count($html, '<input type="radio"'));
        $this->assertStringContainsString('id="field_7-0"', $html);
        $this->assertStringContainsString('id="field_7-2"', $html);
        $this->assertStringContainsString('<label class="sf-rating-label" for="field_7-0"', $html);
        // The passed value is the checked radio.
        $this->assertStringContainsString('value="2" checked', $html);
    }

    // =========================================================================
    // Opinion Scale
    // =========================================================================

    public function testOpinionDefaultsToZeroToTen(): void
    {
        $field = new OpinionScaleFieldType([]);
        $this->assertSame(0, $field->min());
        $this->assertSame(10, $field->max());
        $this->assertSame(range(0, 10), $field->allowedValues());
        $this->assertTrue($field->isChoiceGroup());
    }

    public function testOpinionRespectsConfiguredRange(): void
    {
        $field = new OpinionScaleFieldType(['min' => 1, 'max' => 5]);
        $this->assertSame([1, 2, 3, 4, 5], $field->allowedValues());
    }

    public function testOpinionClampsWideSpanAndInvertedBounds(): void
    {
        // Span > 10 is clamped to min + 10.
        $this->assertSame(10, (new OpinionScaleFieldType(['min' => 0, 'max' => 100]))->max());
        $this->assertSame(15, (new OpinionScaleFieldType(['min' => 5, 'max' => 100]))->max());
        // max < min collapses to min.
        $this->assertSame(3, (new OpinionScaleFieldType(['min' => 3, 'max' => 1]))->max());
    }

    public function testOpinionValidatesInclusiveBounds(): void
    {
        $field = new OpinionScaleFieldType([]);
        $this->assertSame([], $field->validate(0));
        $this->assertSame([], $field->validate(10));
        $this->assertSame([], $field->validate('7'));
        $this->assertNotEmpty($field->validate(11));
        $this->assertNotEmpty($field->validate(-1));
    }

    public function testOpinionNormalizesToInt(): void
    {
        $this->assertSame(0, (new OpinionScaleFieldType([]))->normalizeValue('0'));
        $this->assertSame(9, (new OpinionScaleFieldType([]))->normalizeValue(9));
    }

    public function testOpinionRendersAnchorsAndScaleStrip(): void
    {
        $html = (new OpinionScaleFieldType([
            'min' => 0,
            'max' => 10,
            'leftLabel' => 'Not likely',
            'rightLabel' => 'Very likely',
        ]))->renderInput('field_9', 8);

        $this->assertSame(11, substr_count($html, '<input type="radio"'));
        $this->assertStringContainsString('Not likely', $html);
        $this->assertStringContainsString('Very likely', $html);
        $this->assertStringContainsString('id="field_9-0"', $html);
        $this->assertStringContainsString('value="8" checked', $html);
    }
}

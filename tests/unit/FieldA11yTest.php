<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\fields\CheckboxFieldType;
use anvildev\simpleform\fields\RadioFieldType;
use anvildev\simpleform\fields\TextFieldType;
use PHPUnit\Framework\TestCase;

/**
 * #105 — choice inputs (radio/checkbox) render a unique id + explicit
 * <label for> per option, and single controls expose an id their group label
 * can target. Pure render-string asserts, no Craft boot.
 */
class FieldA11yTest extends TestCase
{
    private const OPTIONS = [
        'options' => [
            ['value' => 'a', 'label' => 'Apple'],
            ['value' => 'b', 'label' => 'Banana'],
        ],
    ];

    public function testRadioOptionsHaveUniqueIdsAndLabels(): void
    {
        $html = (new RadioFieldType(self::OPTIONS))->renderInput('field_7');

        $this->assertStringContainsString('<input type="radio" id="field_7-0" name="field_7" value="a"', $html);
        $this->assertStringContainsString('<label for="field_7-0">Apple</label>', $html);
        $this->assertStringContainsString('id="field_7-1"', $html);
        $this->assertStringContainsString('<label for="field_7-1">Banana</label>', $html);
        // No implicit label-wrapped input remains.
        $this->assertStringNotContainsString('<label><input', $html);
        $this->assertTrue((new RadioFieldType([]))->isChoiceGroup());
    }

    public function testCheckboxOptionsHaveUniqueIdsAndLabels(): void
    {
        $html = (new CheckboxFieldType(self::OPTIONS))->renderInput('field_9');

        $this->assertStringContainsString('<input type="checkbox" id="field_9-0" name="field_9[]" value="a"', $html);
        $this->assertStringContainsString('<label for="field_9-0">Apple</label>', $html);
        $this->assertStringContainsString('id="field_9-1"', $html);
        $this->assertTrue((new CheckboxFieldType([]))->isChoiceGroup());
    }

    public function testSingleControlExposesIdForItsLabel(): void
    {
        $html = (new TextFieldType([]))->renderInput('field_3');

        // The group's <label for="field_3"> needs a matching id on the control.
        $this->assertStringContainsString('id="field_3"', $html);
        $this->assertStringContainsString('name="field_3"', $html);
        $this->assertFalse((new TextFieldType([]))->isChoiceGroup());
    }
}

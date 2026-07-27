<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\fields\ElementRelationFieldType;
use anvildev\simpleform\fields\EntryRelationFieldType;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use PHPUnit\Framework\TestCase;

/**
 * Pure (no Craft boot) tests for the element-relation field type: config
 * accessors, server-side id-membership validation, single/multi/limit rules,
 * and the no-JS public render markup. The allowed-id set and option list — the
 * only methods that touch a live element query — are stubbed so validation and
 * rendering can be exercised in isolation.
 */
class ElementRelationFieldTypeTest extends TestCase
{
    public function testConfigAccessors(): void
    {
        $field = new EntryRelationFieldType([
            'multiple' => true,
            'limit' => 3,
            'sources' => ['products', 'news'],
        ]);

        $this->assertTrue($field->isMultiple());
        $this->assertSame(3, $field->limit());
        $this->assertSame(['products', 'news'], $field->sources());
        $this->assertSame(Entry::class, EntryRelationFieldType::elementType());
        $this->assertSame('entry', EntryRelationFieldType::getType());
    }

    public function testSourcesDefaultsToWildcard(): void
    {
        $this->assertSame(['*'], (new EntryRelationFieldType([]))->sources());
        $this->assertSame(['*'], (new EntryRelationFieldType(['sources' => []]))->sources());
        $this->assertSame(['*'], (new EntryRelationFieldType(['sources' => ['products', '*']]))->sources());
        $this->assertSame(['products'], (new EntryRelationFieldType(['sources' => 'products']))->sources());
    }

    public function testLimitOnlyAcceptsPositiveInts(): void
    {
        $this->assertNull((new EntryRelationFieldType([]))->limit());
        $this->assertNull((new EntryRelationFieldType(['limit' => 0]))->limit());
        $this->assertNull((new EntryRelationFieldType(['limit' => -2]))->limit());
        $this->assertSame(5, (new EntryRelationFieldType(['limit' => '5']))->limit());
    }

    public function testIsChoiceGroupMirrorsMultiple(): void
    {
        $this->assertFalse((new EntryRelationFieldType(['multiple' => false]))->isChoiceGroup());
        $this->assertTrue((new EntryRelationFieldType(['multiple' => true]))->isChoiceGroup());
    }

    public function testValidIdInAllowedSourcePasses(): void
    {
        $field = $this->stubField([5, 6, 7], false);
        $this->assertSame([], $field->validate('5'));
    }

    public function testIdOutsideAllowedSourceFails(): void
    {
        $field = $this->stubField([5, 6, 7], false);
        $errors = $field->validate('99');
        $this->assertSame(['Please select a valid option.'], $errors);
    }

    public function testNonExistentAndForgedValuesFail(): void
    {
        $field = $this->stubField([5], false);
        $this->assertNotSame([], $field->validate('424242'));
        // A non-numeric forged value normalizes to an empty selection, so it
        // passes membership but would fail a required check (none here).
        $this->assertSame([], $field->validate('not-an-id'));
    }

    public function testRequiredEmptyFailsOptionalEmptyPasses(): void
    {
        $required = $this->stubField([5], true);
        $this->assertSame(['This field is required.'], $required->validate(''));
        $this->assertSame(['This field is required.'], $required->validate([]));

        $optional = $this->stubField([5], false);
        $this->assertSame([], $optional->validate(''));
        $this->assertSame([], $optional->validate(null));
    }

    public function testSingleSelectRejectsMultipleValues(): void
    {
        $field = $this->stubField([5, 6], false);
        $errors = $field->validate(['5', '6']);
        $this->assertContains('Only one option may be selected.', $errors);
    }

    public function testMultiSelectAllowsMultipleWithinLimit(): void
    {
        $field = $this->stubField([5, 6, 7], false, true, 3);
        $this->assertSame([], $field->validate(['5', '6', '7']));
    }

    public function testMultiSelectRejectsOverLimit(): void
    {
        $field = $this->stubField([5, 6, 7, 8], false, true, 2);
        $errors = $field->validate(['5', '6', '7']);
        $this->assertContains('Please select no more than 2 options.', $errors);
    }

    public function testRenderSingleSelectMarkup(): void
    {
        $field = $this->stubField([5, 6], false, false, null, [5 => 'Apple', 6 => 'Banana']);
        $html = $field->renderInput('field_3', '6');

        $this->assertStringContainsString('<select id="field_3" name="field_3"', $html);
        $this->assertStringContainsString('<option value="5">Apple</option>', $html);
        $this->assertStringContainsString('<option value="6" selected>Banana</option>', $html);
        $this->assertFalse($field->isChoiceGroup());
    }

    public function testRenderMultiCheckboxMarkup(): void
    {
        $field = $this->stubField([5, 6], false, true, null, [5 => 'Apple', 6 => 'Banana']);
        $html = $field->renderInput('field_9', ['6']);

        $this->assertStringContainsString('<input type="checkbox" id="field_9-0" name="field_9[]" value="5">', $html);
        $this->assertStringContainsString('<label for="field_9-0">Apple</label>', $html);
        $this->assertStringContainsString('value="6" checked', $html);
        $this->assertStringContainsString('<label for="field_9-1">Banana</label>', $html);
        $this->assertTrue($field->isChoiceGroup());
    }

    /**
     * Build a relation field whose allowed-id set and option list are stubbed,
     * so the validation/render paths run without a live element query.
     *
     * @param list<int> $allowedIds
     * @param array<int, string> $options
     */
    private function stubField(
        array $allowedIds,
        bool $required,
        bool $multiple = false,
        ?int $limit = null,
        array $options = [],
    ): ElementRelationFieldType {
        return new class($allowedIds, $options, [ 'required' => $required, 'multiple' => $multiple, 'limit' => $limit, ]) extends ElementRelationFieldType {
            /** @var list<int> */
            private array $stubIds;
            /** @var array<int, string> */
            private array $stubOptions;

            /**
             * @param list<int> $stubIds
             * @param array<int, string> $stubOptions
             * @param array<string, mixed> $config
             */
            public function __construct(array $stubIds, array $stubOptions, array $config)
            {
                parent::__construct($config);
                $this->stubIds = $stubIds;
                $this->stubOptions = $stubOptions;
            }

            public static function getType(): string
            {
                return 'entry';
            }

            public static function getLabel(): string
            {
                return 'Entries';
            }

            public static function elementType(): string
            {
                return Entry::class;
            }

            public function allowedIds(): array
            {
                return $this->stubIds;
            }

            protected function optionList(): array
            {
                return $this->stubOptions;
            }

            protected function applySources(ElementQueryInterface $query): void
            {
            }
        };
    }
}

<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\fields\RepeaterFieldType;
use PHPUnit\Framework\TestCase;

/**
 * Pure, Craft-free seams of the repeater field type (issue #132): row
 * serialization (normalize posted nested arrays → ordered row list, drop empty
 * trailing rows, re-key gaps, JSON-string tolerance, unknown-key stripping) and
 * the bounds helpers. The Craft::t-bearing paths (per-cell validate(), the
 * save-time config validator) are covered by the integration suite, which boots
 * a real Craft with the translation catalogs loaded.
 */
class RepeaterFieldTypeTest extends TestCase
{
    /**
     * @return list<array{handle: string, type: string, label: string, config: array<string, mixed>}>
     */
    private static function defs(): array
    {
        return [
            ['handle' => 'name', 'type' => 'text', 'label' => 'Name', 'config' => []],
            ['handle' => 'email', 'type' => 'email', 'label' => 'Email', 'config' => []],
        ];
    }

    public function testNormalizeKeepsOrderedRowObjects(): void
    {
        $posted = [
            ['name' => 'Ada', 'email' => 'ada@x.io'],
            ['name' => 'Alan', 'email' => 'alan@x.io'],
        ];

        $rows = RepeaterFieldType::normalizeRows($posted, self::defs());

        $this->assertSame([
            ['name' => 'Ada', 'email' => 'ada@x.io'],
            ['name' => 'Alan', 'email' => 'alan@x.io'],
        ], $rows);
    }

    public function testNormalizeDropsTrailingEmptyRows(): void
    {
        $posted = [
            ['name' => 'Ada', 'email' => 'ada@x.io'],
            ['name' => '', 'email' => ''],
            ['name' => '   ', 'email' => ''],
        ];

        // A whitespace-only cell is non-empty (the field type, not the
        // normalizer, decides emptiness), so only the wholly-empty row drops.
        $rows = RepeaterFieldType::normalizeRows($posted, self::defs());

        $this->assertCount(2, $rows);
        $this->assertSame('Ada', $rows[0]['name']);
        $this->assertSame('   ', $rows[1]['name']);
    }

    public function testNormalizeRekeysRemovedIndexGaps(): void
    {
        // The visitor removed row index 1; the post arrives 0,2.
        $posted = [
            0 => ['name' => 'Ada', 'email' => 'ada@x.io'],
            2 => ['name' => 'Alan', 'email' => 'alan@x.io'],
        ];

        $rows = RepeaterFieldType::normalizeRows($posted, self::defs());

        $this->assertSame([0, 1], array_keys($rows));
        $this->assertSame('Alan', $rows[1]['name']);
    }

    public function testNormalizeStripsUnknownInnerKeys(): void
    {
        $posted = [
            ['name' => 'Ada', 'email' => 'ada@x.io', 'evil' => 'injected', 'isAdmin' => '1'],
        ];

        $rows = RepeaterFieldType::normalizeRows($posted, self::defs());

        $this->assertSame(['name', 'email'], array_keys($rows[0]));
        $this->assertArrayNotHasKey('evil', $rows[0]);
    }

    public function testNormalizeToleratesJsonEncodedStringInput(): void
    {
        $json = json_encode([['name' => 'Ada', 'email' => 'ada@x.io']]);

        $rows = RepeaterFieldType::normalizeRows($json, self::defs());

        $this->assertCount(1, $rows);
        $this->assertSame('Ada', $rows[0]['name']);
    }

    public function testNormalizeReturnsEmptyForNonArray(): void
    {
        $this->assertSame([], RepeaterFieldType::normalizeRows(null, self::defs()));
        $this->assertSame([], RepeaterFieldType::normalizeRows('not json', self::defs()));
        $this->assertSame([], RepeaterFieldType::normalizeRows(42, self::defs()));
    }

    public function testNormalizeFillsMissingCellsAsEmptyString(): void
    {
        $posted = [['name' => 'Ada']]; // email cell omitted

        $rows = RepeaterFieldType::normalizeRows($posted, self::defs());

        $this->assertSame('', $rows[0]['email']);
    }

    public function testInnerFieldsDropsNonAllowedAndDuplicateHandles(): void
    {
        $field = new RepeaterFieldType([
            'fields' => [
                ['handle' => 'name', 'type' => 'text', 'label' => 'Name'],
                ['handle' => 'doc', 'type' => 'file', 'label' => 'Doc'],   // disallowed type
                ['handle' => 'name', 'type' => 'email', 'label' => 'Dup'], // duplicate handle
                ['handle' => '', 'type' => 'text', 'label' => 'No handle'], // empty handle
                ['handle' => 'qty', 'type' => 'number', 'label' => 'Qty'],
            ],
        ]);

        $inner = $field->innerFields();

        $handles = array_column($inner, 'handle');
        $this->assertSame(['name', 'qty'], $handles);
    }

    public function testInnerConfigMergesRequiredAndDropsStructuralKeys(): void
    {
        $field = new RepeaterFieldType([
            'fields' => [
                ['handle' => 'qty', 'type' => 'number', 'label' => 'Qty', 'required' => true, 'min' => 1, 'max' => 9],
            ],
        ]);

        $config = $field->innerFields()[0]['config'];

        $this->assertSame(true, $config['required']);
        $this->assertSame(1, $config['min']);
        $this->assertSame(9, $config['max']);
        $this->assertArrayNotHasKey('handle', $config);
        $this->assertArrayNotHasKey('type', $config);
        $this->assertArrayNotHasKey('label', $config);
    }

    public function testMinRowsRespectsRequiredFloor(): void
    {
        $notRequired = new RepeaterFieldType(['minRows' => 0]);
        $this->assertSame(0, $notRequired->minRows());

        $required = new RepeaterFieldType(['minRows' => 0, 'required' => true]);
        $this->assertSame(1, $required->minRows());

        $explicit = new RepeaterFieldType(['minRows' => 3, 'required' => true]);
        $this->assertSame(3, $explicit->minRows());
    }

    public function testMaxRowsZeroMeansUnbounded(): void
    {
        $this->assertSame(0, (new RepeaterFieldType([]))->maxRows());
        $this->assertSame(5, (new RepeaterFieldType(['maxRows' => 5]))->maxRows());
        $this->assertSame(0, (new RepeaterFieldType(['maxRows' => -3]))->maxRows());
    }
}

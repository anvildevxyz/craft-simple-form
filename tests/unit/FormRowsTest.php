<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\helpers\FormRows;
use fabianhaef\simpleform\helpers\FormSteps;
use PHPUnit\Framework\TestCase;

class FormRowsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function field(string $name, mixed $row = null, mixed $page = null): array
    {
        $config = [];
        if ($row !== null) {
            $config['row'] = $row;
        }
        if ($page !== null) {
            $config['page'] = $page;
        }
        return ['name' => $name, 'config' => $config];
    }

    public function testNoRowHintsAreLoneColumns(): void
    {
        $rows = FormRows::group([$this->field('a'), $this->field('b'), $this->field('c')]);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertCount(1, $row);
        }
    }

    public function testAdjacentSameRowJoins(): void
    {
        $rows = FormRows::group([
            $this->field('first', 1),
            $this->field('last', 1),
            $this->field('email'),
        ]);

        $this->assertCount(2, $rows);
        $this->assertSame(['first', 'last'], array_column($rows[0], 'name'));
        $this->assertSame(['email'], array_column($rows[1], 'name'));
    }

    public function testNonAdjacentSameRowValueStartsNewRow(): void
    {
        // Order-driven: a same-numbered row interrupted by another field starts
        // a new visual row rather than reaching back across the gap.
        $rows = FormRows::group([
            $this->field('a', 1),
            $this->field('b', 2),
            $this->field('c', 1),
        ]);

        $this->assertCount(3, $rows);
        $this->assertSame(['a'], array_column($rows[0], 'name'));
        $this->assertSame(['b'], array_column($rows[1], 'name'));
        $this->assertSame(['c'], array_column($rows[2], 'name'));
    }

    public function testCapSpillsToNewRow(): void
    {
        $fields = [];
        for ($i = 1; $i <= 5; $i++) {
            $fields[] = $this->field('f' . $i, 1);
        }

        $rows = FormRows::group($fields);

        $this->assertCount(2, $rows);
        $this->assertCount(FormRows::MAX_COLUMNS, $rows[0]);
        $this->assertCount(1, $rows[1]);
        $this->assertSame(['f5'], array_column($rows[1], 'name'));
    }

    public function testInvalidRowFallsBackToLoneColumn(): void
    {
        $rows = FormRows::group([
            $this->field('a', 0),
            $this->field('b', 'x'),
            $this->field('c', 2),
            $this->field('d', 2),
        ]);

        $this->assertCount(3, $rows);
        $this->assertSame(['a'], array_column($rows[0], 'name'));
        $this->assertSame(['b'], array_column($rows[1], 'name'));
        $this->assertSame(['c', 'd'], array_column($rows[2], 'name'));
    }

    public function testComposesWithSteps(): void
    {
        // Rows are computed per step: step 1 has a 2-column row, step 2 is single.
        $fields = [
            $this->field('first', 1, 1),
            $this->field('last', 1, 1),
            $this->field('comment', null, 2),
        ];

        $steps = FormSteps::group($fields);
        $this->assertCount(2, $steps);

        $step1Rows = FormRows::group($steps[0]);
        $this->assertCount(1, $step1Rows);
        $this->assertSame(['first', 'last'], array_column($step1Rows[0], 'name'));

        $step2Rows = FormRows::group($steps[1]);
        $this->assertCount(1, $step2Rows);
        $this->assertSame(['comment'], array_column($step2Rows[0], 'name'));
    }
}

<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\services\ReportsService;
use PHPUnit\Framework\TestCase;

/**
 * Pure aggregation logic for the survey report (#240): given field meta plus
 * decoded submission payloads, {@see ReportsService::aggregateFieldReport()}
 * must produce correct per-option counts, scale distributions + averages, and
 * free-form response counts — no DB or Craft boot required.
 */
class FieldReportAggregationTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $meta
     * @param list<array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private function aggregate(array $meta, array $rows): array
    {
        // Keyed by field key for convenient assertions.
        $out = [];
        foreach ((new ReportsService())->aggregateFieldReport($meta, $rows) as $row) {
            $out[$row['key']] = $row;
        }
        return $out;
    }

    public function testChoiceCountsOptionsAndZeroFillsUnpicked(): void
    {
        $meta = [[
            'key' => 'field_1',
            'label' => 'Colour',
            'type' => 'select',
            'kind' => 'choice',
            'options' => ['red' => 'Red', 'green' => 'Green', 'blue' => 'Blue'],
        ]];
        $rows = [
            ['field_1' => ['value' => 'red']],
            ['field_1' => ['value' => 'red']],
            ['field_1' => ['value' => 'blue']],
            ['field_1' => ['value' => '']],   // unanswered
            [],                                // field absent
        ];

        $report = $this->aggregate($meta, $rows)['field_1'];

        $this->assertSame(3, $report['count'], 'three real answers');
        // Options preserve authored order, including the zero-picked one.
        $this->assertSame(
            [
                ['value' => 'red', 'label' => 'Red', 'count' => 2],
                ['value' => 'green', 'label' => 'Green', 'count' => 0],
                ['value' => 'blue', 'label' => 'Blue', 'count' => 1],
            ],
            $report['options'],
        );
    }

    public function testCheckboxCountsEachSelectionButRespondentOnce(): void
    {
        $meta = [[
            'key' => 'field_2',
            'label' => 'Toppings',
            'type' => 'checkbox',
            'kind' => 'choice',
            'options' => ['cheese' => 'Cheese', 'olives' => 'Olives'],
        ]];
        $rows = [
            ['field_2' => ['value' => ['cheese', 'olives']]],
            ['field_2' => ['value' => ['cheese']]],
            ['field_2' => ['value' => []]], // unanswered
        ];

        $report = $this->aggregate($meta, $rows)['field_2'];

        // Two respondents, but cheese was picked twice.
        $this->assertSame(2, $report['count']);
        $counts = array_column($report['options'], 'count', 'value');
        $this->assertSame(2, $counts['cheese']);
        $this->assertSame(1, $counts['olives']);
    }

    public function testChoiceSurfacesValueNoLongerInOptionSet(): void
    {
        $meta = [[
            'key' => 'field_3',
            'label' => 'Plan',
            'type' => 'radio',
            'kind' => 'choice',
            'options' => ['pro' => 'Pro'],
        ]];
        $rows = [
            ['field_3' => ['value' => 'pro']],
            ['field_3' => ['value' => 'legacy']], // option since removed
        ];

        $report = $this->aggregate($meta, $rows)['field_3'];

        $counts = array_column($report['options'], 'count', 'value');
        $this->assertSame(1, $counts['pro']);
        $this->assertSame(1, $counts['legacy'], 'orphaned value still counted, labelled by its value');
    }

    public function testScaleComputesAverageAndZeroFilledDistribution(): void
    {
        $meta = [[
            'key' => 'field_4',
            'label' => 'Score',
            'type' => 'rating',
            'kind' => 'scale',
            'points' => [1, 2, 3, 4, 5],
        ]];
        $rows = [
            ['field_4' => ['value' => 5]],
            ['field_4' => ['value' => 5]],
            ['field_4' => ['value' => 3]],
            ['field_4' => ['value' => '4']], // integer-string from a forged/legacy row
            ['field_4' => ['value' => null]], // unanswered
        ];

        $report = $this->aggregate($meta, $rows)['field_4'];

        $this->assertSame(4, $report['count']);
        // (5 + 5 + 3 + 4) / 4 = 4.25 → 4.3 at one decimal.
        $this->assertSame(4.3, $report['average']);
        $this->assertSame([1 => 0, 2 => 0, 3 => 1, 4 => 1, 5 => 2], $report['distribution']);
    }

    public function testFreeFormCountsResponsesOnly(): void
    {
        $meta = [[
            'key' => 'field_5',
            'label' => 'Comments',
            'type' => 'textarea',
            'kind' => 'none',
        ]];
        $rows = [
            ['field_5' => ['value' => 'Loved it']],
            ['field_5' => ['value' => '']], // empty doesn't count
            ['field_5' => ['value' => 'More please']],
        ];

        $report = $this->aggregate($meta, $rows)['field_5'];

        $this->assertSame(2, $report['count']);
        $this->assertArrayNotHasKey('options', $report);
        $this->assertArrayNotHasKey('distribution', $report);
    }

    public function testEmptyRowsYieldZeroCounts(): void
    {
        $meta = [[
            'key' => 'field_6',
            'label' => 'Rating',
            'type' => 'rating',
            'kind' => 'scale',
            'points' => [1, 2, 3],
        ]];

        $report = $this->aggregate($meta, [])['field_6'];

        $this->assertSame(0, $report['count']);
        $this->assertSame(0.0, $report['average']);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0], $report['distribution']);
    }
}

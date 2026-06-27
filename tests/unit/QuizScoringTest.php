<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\services\QuizScoringService;
use PHPUnit\Framework\TestCase;

/**
 * Pure quiz scoring (#241): {@see QuizScoringService::computeScore()} and the
 * grade-band parser, exercised without a DB or Craft boot.
 */
class QuizScoringTest extends TestCase
{
    private function service(): QuizScoringService
    {
        return new QuizScoringService();
    }

    /**
     * @param array<int, array{value: string, correct?: bool, points?: int}> $options
     * @return array{key: string, options: array<int, mixed>}
     */
    private function field(string $key, array $options): array
    {
        return ['key' => $key, 'options' => $options];
    }

    public function testSingleChoiceAwardsCorrectAnswer(): void
    {
        $fields = [$this->field('field_1', [
            ['value' => 'a', 'correct' => true],
            ['value' => 'b'],
        ])];

        $right = $this->service()->computeScore($fields, ['field_1' => ['value' => 'a']]);
        $this->assertSame(1, $right['score']);
        $this->assertSame(1, $right['maxScore']);
        $this->assertSame(100, $right['percentage']);

        $wrong = $this->service()->computeScore($fields, ['field_1' => ['value' => 'b']]);
        $this->assertSame(0, $wrong['score']);
        $this->assertSame(1, $wrong['maxScore']);
        $this->assertSame(0, $wrong['percentage']);
    }

    public function testPointsWeightingAndDefaults(): void
    {
        $fields = [
            $this->field('field_1', [
                ['value' => 'a', 'correct' => true, 'points' => 3],
                ['value' => 'b'],
            ]),
            $this->field('field_2', [
                // correct without explicit points defaults to weight 1
                ['value' => 'x', 'correct' => true],
            ]),
        ];

        $result = $this->service()->computeScore($fields, [
            'field_1' => ['value' => 'a'],
            'field_2' => ['value' => 'x'],
        ]);

        $this->assertSame(4, $result['score']);
        $this->assertSame(4, $result['maxScore']);
        $this->assertSame(100, $result['percentage']);
    }

    public function testMultiSelectSumsCorrectSelectionsOnly(): void
    {
        $fields = [$this->field('field_1', [
            ['value' => 'a', 'correct' => true, 'points' => 2],
            ['value' => 'b', 'correct' => true, 'points' => 2],
            ['value' => 'c'], // distractor
        ])];

        // Picks one correct + one distractor: earns 2 of a possible 4.
        $result = $this->service()->computeScore($fields, [
            'field_1' => ['value' => ['a', 'c']],
        ]);

        $this->assertSame(2, $result['score']);
        $this->assertSame(4, $result['maxScore']);
        $this->assertSame(50, $result['percentage']);
    }

    public function testNoAnswerKeyYieldsNullPercentage(): void
    {
        $fields = [$this->field('field_1', [
            ['value' => 'a'],
            ['value' => 'b'],
        ])];

        $result = $this->service()->computeScore($fields, ['field_1' => ['value' => 'a']]);
        $this->assertSame(0, $result['score']);
        $this->assertSame(0, $result['maxScore']);
        $this->assertNull($result['percentage']);
        $this->assertNull($result['grade']);
    }

    public function testUnansweredFieldScoresZeroButCountsTowardMax(): void
    {
        $fields = [$this->field('field_1', [
            ['value' => 'a', 'correct' => true, 'points' => 5],
        ])];

        // Field present in data (was visible) but left blank.
        $result = $this->service()->computeScore($fields, ['field_1' => ['value' => '']]);
        $this->assertSame(0, $result['score']);
        $this->assertSame(5, $result['maxScore']);
        $this->assertSame(0, $result['percentage']);
    }

    public function testGradeBandsAssignHighestMetThreshold(): void
    {
        $bands = $this->service()->parseGradeBands("90 Excellent\n70 Pass\n0 Fail");

        $fields = [$this->field('field_1', [
            ['value' => 'a', 'correct' => true, 'points' => 10],
        ])];

        $full = $this->service()->computeScore($fields, ['field_1' => ['value' => 'a']], $bands);
        $this->assertSame(100, $full['percentage']);
        $this->assertSame('Excellent', $full['grade']);

        $none = $this->service()->computeScore($fields, ['field_1' => ['value' => 'z']], $bands);
        $this->assertSame(0, $none['percentage']);
        $this->assertSame('Fail', $none['grade']);
    }

    public function testParseGradeBandsSortsAndIgnoresJunk(): void
    {
        $bands = $this->service()->parseGradeBands("70 Pass\nnonsense line\n90 Top\n  50  Borderline pass  ");

        $this->assertSame([
            ['min' => 90, 'label' => 'Top'],
            ['min' => 70, 'label' => 'Pass'],
            ['min' => 50, 'label' => 'Borderline pass'],
        ], $bands);
    }
}

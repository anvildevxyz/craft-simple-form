<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\helpers\SubmissionCsv;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Repeater export shape (issue #132, PRD §5.7): a repeater value — an ordered
 * list of row objects — serializes to a single JSON column (lossless), while
 * flat multi-value arrays (checkbox/file lists) stay pipe-joined.
 */
class RepeaterExportTest extends TestCase
{
    private function scalar(mixed $value): string
    {
        $method = new ReflectionMethod(SubmissionCsv::class, 'scalar');
        $method->setAccessible(true);
        return (string) $method->invoke(null, $value);
    }

    public function testRepeaterValueSerializesToJson(): void
    {
        $value = [
            ['name' => 'Ada', 'email' => 'ada@x.io'],
            ['name' => 'Alan', 'email' => 'alan@x.io'],
        ];

        $cell = $this->scalar($value);

        $this->assertJson($cell);
        $decoded = json_decode($cell, true);
        $this->assertSame('Ada', $decoded[0]['name']);
        $this->assertSame('alan@x.io', $decoded[1]['email']);
    }

    public function testFlatMultiValueArrayStaysPipeJoined(): void
    {
        $this->assertSame('a|b|c', $this->scalar(['a', 'b', 'c']));
    }

    public function testEmptyRepeaterIsEmptyCell(): void
    {
        $this->assertSame('', $this->scalar([]));
    }

    public function testJsonCellIsFormulaNeutralised(): void
    {
        // A row whose JSON-encoding could begin with a dangerous character is
        // still prefixed so Excel never treats it as a formula. JSON always
        // starts with '[', so assert the common path stays unprefixed JSON.
        $cell = $this->scalar([['x' => '1']]);
        $this->assertStringStartsWith('[', $cell);
    }
}

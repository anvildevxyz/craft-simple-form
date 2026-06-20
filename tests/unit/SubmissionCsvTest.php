<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\helpers\SubmissionCsv;
use PHPUnit\Framework\TestCase;

/**
 * Guards the CSV formula-injection neutralisation (audit finding F1, CWE-1236).
 */
class SubmissionCsvTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function dangerousCells(): array
    {
        return [
            'equals formula' => ['=HYPERLINK("http://evil.test","x")', "'=HYPERLINK(\"http://evil.test\",\"x\")"],
            'plus formula' => ['+1+1', "'+1+1"],
            'minus formula' => ['-2+3', "'-2+3"],
            'at command' => ['@SUM(A1:A9)', "'@SUM(A1:A9)"],
            'tab prefix' => ["\t=1", "'\t=1"],
            'carriage return prefix' => ["\r=1", "'\r=1"],
        ];
    }

    /**
     * @dataProvider dangerousCells
     */
    public function testDangerousCellsArePrefixed(string $input, string $expected): void
    {
        $this->assertSame($expected, SubmissionCsv::neutralizeFormula($input));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function safeCells(): array
    {
        return [
            'plain text' => ['hello world'],
            'email' => ['foo@bar.com'],
            'number' => ['42'],
            'empty' => [''],
            'inner equals' => ['a=b'],
        ];
    }

    /**
     * @dataProvider safeCells
     */
    public function testSafeCellsAreUnchanged(string $input): void
    {
        $this->assertSame($input, SubmissionCsv::neutralizeFormula($input));
    }
}

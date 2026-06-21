<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\exceptions\FormulaException;
use fabianhaef\simpleform\helpers\Formula;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for the safe Calculation-field formula engine. Pure PHP with
 * no Craft dependency, so it asserts real evaluation results. The front-end JS
 * port (tests/js/formula.test.js) must produce identical results for the same
 * cases.
 */
class FormulaTest extends TestCase
{
    // --- Precedence & associativity --------------------------------------

    public function testPrecedence(): void
    {
        $this->assertSame(14.0, Formula::evaluate('2 + 3 * 4', []));
        $this->assertSame(20.0, Formula::evaluate('(2 + 3) * 4', []));
        $this->assertSame(2.0, Formula::evaluate('10 - 4 - 4', []));
        $this->assertSame(8.0, Formula::evaluate('2 * 2 * 2', []));
    }

    public function testNestedParentheses(): void
    {
        $this->assertSame(54.0, Formula::evaluate('((2 + 1) * (3 + 3)) * 3', []));
    }

    public function testUnarySign(): void
    {
        $this->assertSame(-5.0, Formula::evaluate('-5', []));
        $this->assertSame(5.0, Formula::evaluate('--5', []));
        $this->assertSame(3.0, Formula::evaluate('5 + -2', []));
    }

    public function testDecimals(): void
    {
        $this->assertSame(0.75, Formula::evaluate('1.5 / 2', []));
        $this->assertSame(0.5, Formula::evaluate('.5', []));
    }

    // --- References ------------------------------------------------------

    public function testReferences(): void
    {
        $this->assertSame(30.0, Formula::evaluate('{quantity} * {unitPrice}', [
            'quantity' => 3,
            'unitPrice' => 10,
        ]));
    }

    public function testNumericStringReference(): void
    {
        // A select/radio whose option value is numeric resolves to that number.
        $this->assertSame(25.0, Formula::evaluate('{tier}', ['tier' => '25']));
    }

    public function testMissingReferenceResolvesToZero(): void
    {
        $this->assertSame(1.0, Formula::evaluate('{nope} + 1', []));
        $this->assertSame(0.0, Formula::evaluate('{a} * {b}', ['a' => 5]));
    }

    public function testNonNumericReferenceResolvesToZero(): void
    {
        $this->assertSame(0.0, Formula::evaluate('{name} * 10', ['name' => 'Alice']));
        $this->assertSame(0.0, Formula::evaluate('{empty} + 0', ['empty' => '']));
    }

    public function testArrayReferenceResolvesToZero(): void
    {
        $this->assertSame(0.0, Formula::evaluate('{boxes} + 0', ['boxes' => ['a', 'b']]));
    }

    // --- Functions -------------------------------------------------------

    public function testFunctions(): void
    {
        $this->assertSame(2.0, Formula::evaluate('min(2, 5, 9)', []));
        $this->assertSame(9.0, Formula::evaluate('max(2, 5, 9)', []));
        $this->assertSame(2.35, Formula::evaluate('round(2.345, 2)', []));
        $this->assertSame(2.0, Formula::evaluate('round(2.4)', []));
        $this->assertSame(3.0, Formula::evaluate('ceil(2.1)', []));
        $this->assertSame(2.0, Formula::evaluate('floor(2.9)', []));
        $this->assertSame(4.0, Formula::evaluate('abs(-4)', []));
    }

    public function testFunctionsAreCaseInsensitive(): void
    {
        $this->assertSame(9.0, Formula::evaluate('MAX(2, 9)', []));
    }

    public function testFunctionWithExpressionArguments(): void
    {
        $this->assertSame(7.0, Formula::evaluate('round({tier} * (1 + {fees} * 0.05), 0)', [
            'tier' => 6.6,
            'fees' => 1,
        ]));
    }

    public function testArityErrorsThrow(): void
    {
        $this->expectException(FormulaException::class);
        Formula::evaluate('abs(1, 2)', []);
    }

    public function testCeilArityErrorThrows(): void
    {
        $this->expectException(FormulaException::class);
        Formula::evaluate('ceil(1, 2)', []);
    }

    // --- Divide by zero --------------------------------------------------

    public function testDivideByZeroYieldsZero(): void
    {
        $this->assertSame(0.0, Formula::evaluate('5 / 0', []));
        $this->assertSame(0.0, Formula::evaluate('5 / {zero}', ['zero' => 0]));
        $this->assertSame(0.0, Formula::evaluate('{a} / {b}', ['a' => 10, 'b' => 0]));
    }

    public function testEmptyFormulaIsZero(): void
    {
        $this->assertSame(0.0, Formula::evaluate('', []));
        $this->assertSame(0.0, Formula::evaluate('   ', []));
    }

    // --- Injection / allow-list rejection --------------------------------

    /**
     * @dataProvider rejectProvider
     */
    public function testRejectsDisallowedInput(string $formula): void
    {
        $this->expectException(FormulaException::class);
        Formula::evaluate($formula, ['a' => 1]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectProvider(): array
    {
        return [
            'php call' => ['phpinfo()'],
            'unknown function' => ['foo(1)'],
            'power operator' => ['2 ** 3'],
            'modulo' => ['5 % 2'],
            'unterminated ref' => ['{handle'],
            'semicolon' => ['1; 2'],
            'brackets' => ['[1, 2]'],
            'backtick' => ['`whoami`'],
            'string literal' => ['"x"'],
            'bitwise' => ['1 & 2'],
            'trailing operator' => ['1 +'],
            'double operator' => ['1 * * 2'],
            'unbalanced paren' => ['(1 + 2'],
            'empty parens' => ['()'],
            'invalid ref chars' => ['{a-b}'],
            'variable assignment' => ['a = 1'],
            'dollar var' => ['$x'],
        ];
    }

    public function testReferencesHarvest(): void
    {
        $this->assertSame(['quantity', 'unitPrice'], Formula::references('{quantity} * {unitPrice} + {quantity}'));
        $this->assertSame([], Formula::references('2 + 3'));
    }

    public function testReferencesThrowOnSyntaxError(): void
    {
        $this->expectException(FormulaException::class);
        Formula::references('{a} + phpinfo()');
    }
}

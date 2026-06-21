<?php

namespace fabianhaef\simpleform\helpers;

use fabianhaef\simpleform\exceptions\FormulaException;

/**
 * Safe, side-effect-free arithmetic expression engine for the Calculation field
 * (#131). Pure PHP with no Craft/Yii dependency so it is trivially unit-testable
 * and shares its grammar with the front-end JS port
 * (src/web/assets/form/dist/js/simple-form.js).
 *
 * The grammar is deliberately tiny and allow-listed:
 *
 *   expr    := term (('+' | '-') term)*
 *   term    := factor (('*' | '/') factor)*
 *   factor  := NUMBER
 *            | '{' HANDLE '}'
 *            | '(' expr ')'
 *            | ('+' | '-') factor          (unary sign)
 *            | FUNCTION '(' expr (',' expr)* ')'
 *   FUNCTION := min | max | round | ceil | floor | abs
 *
 * There is **no** `eval`, no PHP callable sourced from input, no Twig, and no
 * variable-variable indirection. Function dispatch is a hardcoded `match`. Any
 * character or token outside the allow-list raises {@see FormulaException}, which
 * the save-time validator surfaces as a translated field error; runtime
 * evaluation (already-validated formulas) is total — divide-by-zero yields 0.0
 * and a missing/non-numeric reference resolves to 0.0.
 *
 * @author Fabian Haefliger
 * @since 5.x
 */
final class Formula
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    /**
     * Allow-listed function names and their required arity (null = variadic with
     * a minimum of one argument, enforced per-function below).
     *
     * @var array<string, int|null>
     */
    public const FUNCTIONS = [
        'min' => null,
        'max' => null,
        'round' => null,
        'ceil' => 1,
        'floor' => 1,
        'abs' => 1,
    ];

    /** Hard cap on token count to reject pathologically large formulas. */
    private const MAX_TOKENS = 256;

    /** Hard cap on parser recursion depth to reject pathological nesting. */
    private const MAX_DEPTH = 64;

    // =========================================================================
    // PRIVATE PROPERTIES
    // =========================================================================

    /** @var list<array{type: string, value: string}> */
    private array $tokens;

    /** @var array<string, float> handle => numeric value */
    private array $refs;

    private int $pos = 0;

    private int $depth = 0;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * @param list<array{type: string, value: string}> $tokens
     * @param array<string, float> $refs
     */
    private function __construct(array $tokens, array $refs)
    {
        $this->tokens = $tokens;
        $this->refs = $refs;
    }

    /**
     * Evaluate a formula against a `handle => value` map, returning the numeric
     * result. Total at runtime: missing/non-numeric references resolve to 0.0 and
     * divide-by-zero yields 0.0. Only **malformed** input throws.
     *
     * @param array<string, mixed> $valuesByHandle
     * @throws FormulaException when the formula is syntactically invalid
     */
    public static function evaluate(string $formula, array $valuesByHandle, bool $missingAsZero = true): float
    {
        $tokens = self::tokenize($formula);
        if ($tokens === []) {
            return 0.0;
        }

        $refs = self::numericRefs($valuesByHandle, $missingAsZero);

        $parser = new self($tokens, $refs);
        $result = $parser->parseExpr();
        $parser->expectEnd();

        if (!is_finite($result)) {
            return 0.0;
        }

        return $result;
    }

    /**
     * Tokenise a formula into an allow-listed token list, rejecting any
     * unrecognised character. Public so save-time validation can assert a formula
     * parses (and harvest its `{handle}` references) without evaluating it.
     *
     * @return list<array{type: string, value: string}>
     * @throws FormulaException when an unexpected character is encountered
     */
    public static function tokenize(string $formula): array
    {
        $tokens = [];
        $length = strlen($formula);
        $i = 0;

        while ($i < $length) {
            $char = $formula[$i];

            if (ctype_space($char)) {
                $i++;
                continue;
            }

            // Number literal: digits with an optional single decimal point.
            if (ctype_digit($char) || ($char === '.' && $i + 1 < $length && ctype_digit($formula[$i + 1]))) {
                $number = '';
                $seenDot = false;
                while ($i < $length && (ctype_digit($formula[$i]) || $formula[$i] === '.')) {
                    if ($formula[$i] === '.') {
                        if ($seenDot) {
                            throw new FormulaException('Malformed number literal.');
                        }
                        $seenDot = true;
                    }
                    $number .= $formula[$i];
                    $i++;
                }
                $tokens[] = ['type' => 'number', 'value' => $number];
                self::guardCount($tokens);
                continue;
            }

            // Field reference: {handle}. Handle grammar matches the field-handle
            // rule (a letter/underscore start, then word characters).
            if ($char === '{') {
                $close = strpos($formula, '}', $i);
                if ($close === false) {
                    throw new FormulaException('Unterminated field reference.');
                }
                $handle = substr($formula, $i + 1, $close - $i - 1);
                if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $handle)) {
                    throw new FormulaException('Invalid field reference: {' . $handle . '}.');
                }
                $tokens[] = ['type' => 'ref', 'value' => $handle];
                self::guardCount($tokens);
                $i = $close + 1;
                continue;
            }

            // Bareword: an allow-listed function name only.
            if (ctype_alpha($char)) {
                $word = '';
                while ($i < $length && (ctype_alnum($formula[$i]) || $formula[$i] === '_')) {
                    $word .= $formula[$i];
                    $i++;
                }
                if (!array_key_exists(strtolower($word), self::FUNCTIONS)) {
                    throw new FormulaException('Unknown function: ' . $word . '.');
                }
                $tokens[] = ['type' => 'func', 'value' => strtolower($word)];
                self::guardCount($tokens);
                continue;
            }

            // Single-character operators and grouping.
            if (in_array($char, ['+', '-', '*', '/', '(', ')', ','], true)) {
                $tokens[] = ['type' => $char, 'value' => $char];
                self::guardCount($tokens);
                $i++;
                continue;
            }

            throw new FormulaException('Unexpected character: ' . $char . '.');
        }

        return $tokens;
    }

    /**
     * The distinct `{handle}` references in a formula, for save-time validation
     * (every reference must resolve to an existing field). Throws on malformed
     * syntax so an unparseable formula is rejected before it ships.
     *
     * @return list<string>
     * @throws FormulaException when the formula is syntactically invalid
     */
    public static function references(string $formula): array
    {
        $handles = [];
        foreach (self::tokenize($formula) as $token) {
            if ($token['type'] === 'ref') {
                $handles[$token['value']] = true;
            }
        }

        return array_keys($handles);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * Coerce a raw `handle => value` snapshot to a numeric map. Non-numeric and
     * empty values resolve to 0.0 (the missing case is handled at lookup time so
     * the `missingAsZero` flag can distinguish absent from present-but-empty).
     *
     * @param array<string, mixed> $valuesByHandle
     * @return array<string, float>
     */
    private static function numericRefs(array $valuesByHandle, bool $missingAsZero): array
    {
        $refs = [];
        foreach ($valuesByHandle as $handle => $value) {
            if (is_array($value)) {
                // Multi-value (checkbox group) has no scalar numeric meaning.
                $refs[$handle] = 0.0;
                continue;
            }
            $refs[$handle] = is_numeric($value) ? (float) $value : 0.0;
        }

        // When missingAsZero is off the lookup still resolves to 0.0 (formula
        // grammar has no notion of "skip"); the flag is reserved for future
        // strict modes and documented as no-op in v1.
        unset($missingAsZero);

        return $refs;
    }

    /**
     * @param list<array{type: string, value: string}> $tokens
     * @throws FormulaException
     */
    private static function guardCount(array $tokens): void
    {
        if (count($tokens) > self::MAX_TOKENS) {
            throw new FormulaException('Formula is too long.');
        }
    }

    /** expr := term (('+' | '-') term)* */
    private function parseExpr(): float
    {
        $this->enter();
        $value = $this->parseTerm();

        while ($this->is('+') || $this->is('-')) {
            $op = $this->next()['type'];
            $rhs = $this->parseTerm();
            $value = $op === '+' ? $value + $rhs : $value - $rhs;
        }

        $this->leave();
        return $value;
    }

    /** term := factor (('*' | '/') factor)* */
    private function parseTerm(): float
    {
        $this->enter();
        $value = $this->parseFactor();

        while ($this->is('*') || $this->is('/')) {
            $op = $this->next()['type'];
            $rhs = $this->parseFactor();
            if ($op === '*') {
                $value *= $rhs;
            } else {
                // Divide-by-zero is total: yields 0.0, never INF/NAN/error.
                $value = $rhs == 0.0 ? 0.0 : $value / $rhs;
            }
        }

        $this->leave();
        return $value;
    }

    /**
     * factor := NUMBER | ref | '(' expr ')' | sign factor | func '(' args ')'
     *
     * @throws FormulaException
     */
    private function parseFactor(): float
    {
        $this->enter();
        $token = $this->peek();
        if ($token === null) {
            throw new FormulaException('Unexpected end of formula.');
        }

        // Unary sign.
        if ($token['type'] === '+' || $token['type'] === '-') {
            $this->next();
            $operand = $this->parseFactor();
            $this->leave();
            return $token['type'] === '-' ? -$operand : $operand;
        }

        if ($token['type'] === 'number') {
            $this->next();
            $this->leave();
            return (float) $token['value'];
        }

        if ($token['type'] === 'ref') {
            $this->next();
            $this->leave();
            return $this->refs[$token['value']] ?? 0.0;
        }

        if ($token['type'] === '(') {
            $this->next();
            $value = $this->parseExpr();
            $this->expect(')');
            $this->leave();
            return $value;
        }

        if ($token['type'] === 'func') {
            $value = $this->parseFunction($token['value']);
            $this->leave();
            return $value;
        }

        throw new FormulaException('Unexpected token: ' . $token['value'] . '.');
    }

    /**
     * Parse a function call and dispatch to a hardcoded handler. No PHP callable
     * is ever derived from the input name.
     *
     * @throws FormulaException
     */
    private function parseFunction(string $name): float
    {
        $this->next();
        $this->expect('(');

        $args = [$this->parseExpr()];
        while ($this->is(',')) {
            $this->next();
            $args[] = $this->parseExpr();
        }
        $this->expect(')');

        $arity = self::FUNCTIONS[$name];
        if ($arity !== null && count($args) !== $arity) {
            throw new FormulaException(sprintf('Function %s expects %d argument(s).', $name, $arity));
        }

        return match ($name) {
            'min' => min($args),
            'max' => max($args),
            'abs' => abs($args[0]),
            'ceil' => ceil($args[0]),
            'floor' => floor($args[0]),
            'round' => count($args) === 1
                ? round($args[0])
                : (count($args) === 2
                    ? round($args[0], (int) $args[1])
                    : throw new FormulaException('Function round expects 1 or 2 argument(s).')),
            default => throw new FormulaException('Unknown function: ' . $name . '.'),
        };
    }

    // ---- token cursor -------------------------------------------------------

    /** @return array{type: string, value: string}|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    /**
     * @return array{type: string, value: string}
     * @throws FormulaException
     */
    private function next(): array
    {
        $token = $this->tokens[$this->pos] ?? null;
        if ($token === null) {
            throw new FormulaException('Unexpected end of formula.');
        }
        $this->pos++;
        return $token;
    }

    private function is(string $type): bool
    {
        return ($this->tokens[$this->pos]['type'] ?? null) === $type;
    }

    /** @throws FormulaException */
    private function expect(string $type): void
    {
        if (!$this->is($type)) {
            throw new FormulaException('Expected "' . $type . '".');
        }
        $this->pos++;
    }

    /** @throws FormulaException */
    private function expectEnd(): void
    {
        if ($this->pos !== count($this->tokens)) {
            throw new FormulaException('Unexpected trailing tokens.');
        }
    }

    /** @throws FormulaException */
    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new FormulaException('Formula is nested too deeply.');
        }
    }

    private function leave(): void
    {
        $this->depth--;
    }
}

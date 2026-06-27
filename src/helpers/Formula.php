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
 * @phpstan-type Token array{type: string, value: string}
 *
 * @author Fabian Haefliger
 * @since 1.0.0
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

    private int $pos = 0;

    private int $depth = 0;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * @param list<Token> $tokens
     * @param array<string, float> $refs
     */
    private function __construct(
        /** @var list<Token> */
        private array $tokens,
        /** @var array<string, float> handle => numeric value */
        private array $refs,
    ) {
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
     * @return list<Token>
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
                while ($i < $length && (ctype_digit($c = $formula[$i]) || $c === '.')) {
                    if ($c === '.') {
                        if ($seenDot) {
                            throw new FormulaException('Malformed number literal.');
                        }
                        $seenDot = true;
                    }
                    $number .= $c;
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
                while ($i < $length && (ctype_alnum($c = $formula[$i]) || $c === '_')) {
                    $word .= $c;
                    $i++;
                }
                $lower = strtolower($word);
                if (!array_key_exists($lower, self::FUNCTIONS)) {
                    throw new FormulaException('Unknown function: ' . $word . '.');
                }
                $tokens[] = ['type' => 'func', 'value' => $lower];
                self::guardCount($tokens);
                continue;
            }

            // Single-character operators and grouping.
            if (str_contains('+-*/(),', $char)) {
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
            // Multi-value (checkbox group) has no scalar numeric meaning -> 0.0.
            $refs[$handle] = (!is_array($value) && is_numeric($value)) ? (float) $value : 0.0;
        }

        // missingAsZero is a documented no-op in v1: the lookup always resolves
        // to 0.0 (the grammar has no notion of "skip"); reserved for strict modes.
        unset($missingAsZero);

        return $refs;
    }

    /**
     * @param list<Token> $tokens
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

        $this->depth--;
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

        $this->depth--;
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
        if (($t = $token['type']) === '+' || $t === '-') {
            $this->next();
            $operand = $this->parseFactor();
            $this->depth--;
            return $t === '-' ? -$operand : $operand;
        }

        if ($t === 'number') {
            $this->next();
            $this->depth--;
            return (float) $token['value'];
        }

        if ($t === 'ref') {
            $this->next();
            $this->depth--;
            return $this->refs[$token['value']] ?? 0.0;
        }

        if ($t === '(') {
            $this->next();
            $value = $this->parseExpr();
            $this->expect(')');
            $this->depth--;
            return $value;
        }

        if ($t === 'func') {
            $value = $this->parseFunction($token['value']);
            $this->depth--;
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

    /** @return Token|null */
    private function peek(): ?array
    {
        return $this->tokens[$this->pos] ?? null;
    }

    /**
     * @return Token
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
}

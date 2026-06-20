<?php

namespace fabianhaef\simpleform\helpers;

/**
 * Pure, framework-agnostic evaluator for field conditional logic.
 *
 * A field may carry a `conditional` block inside its config:
 *
 *   'conditional' => [
 *       'enabled'  => true,
 *       'action'   => 'show' | 'hide',     // visibility behaviour
 *       'match'    => 'all' | 'any',        // AND / OR across rules
 *       'rules'    => [
 *           ['field' => 'accountType', 'operator' => 'eq', 'value' => 'business'],
 *           ...
 *       ],
 *       'required' => [                      // optional, independent block
 *           'enabled' => true,
 *           'match'   => 'all' | 'any',
 *           'rules'   => [ ... ],
 *       ],
 *   ]
 *
 * Rules reference their target by field **handle** (`field`). Handles are
 * unique within a form and known client-side for not-yet-saved fields too, so
 * the editor, the server, and the front-end all share one key space with no id
 * resolution. (The editor rewrites references when a handle is renamed; the
 * server prunes references to handles that no longer exist.)
 *
 * This class is the single source of truth for evaluation semantics. The
 * front-end JS evaluator mirrors the same operator table and show/hide/match
 * rules; both are covered by parallel tests so they cannot drift silently.
 *
 * It is deliberately free of any Craft/Yii dependency: it takes a field config
 * array and a flat `handle => value` map and returns booleans. The server
 * (SubmissionService) builds that map from the submitted snapshot; the values
 * are the raw posted values (string, array for multi-value, or null).
 */
class ConditionalEvaluator
{
    public const ACTION_SHOW = 'show';
    public const ACTION_HIDE = 'hide';

    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    public const OPERATORS = ['eq', 'neq', 'empty', 'notEmpty', 'contains', 'gt', 'lt'];

    /**
     * Is a field visible given the current form values?
     *
     * Fields with no (or disabled) conditional block, or with an empty rule
     * set, are always visible — so existing forms behave exactly as before.
     *
     * @param array<string, mixed> $config field config (may contain `conditional`)
     * @param array<string, mixed> $values posted values keyed by field handle
     */
    public static function isVisible(array $config, array $values): bool
    {
        $conditional = $config['conditional'] ?? null;
        if (!is_array($conditional) || empty($conditional['enabled'])) {
            return true;
        }

        $rules = $conditional['rules'] ?? [];
        if (!is_array($rules) || $rules === []) {
            return true;
        }

        $action = ($conditional['action'] ?? self::ACTION_SHOW) === self::ACTION_HIDE
            ? self::ACTION_HIDE
            : self::ACTION_SHOW;

        $rulesMatch = self::rulesMatch($rules, self::normalizeMatch($conditional['match'] ?? self::MATCH_ALL), $values);

        // show: visible when rules match. hide: visible when they don't.
        return $action === self::ACTION_SHOW ? $rulesMatch : !$rulesMatch;
    }

    /**
     * Does the conditional-required block trigger for the current values?
     *
     * Returns false when there is no enabled `required` block — the field's
     * static `required` flag is handled separately by the caller, which ORs the
     * two together (and skips required entirely for hidden fields).
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $values
     */
    public static function isRequiredByCondition(array $config, array $values): bool
    {
        $conditional = $config['conditional'] ?? null;
        if (!is_array($conditional)) {
            return false;
        }

        $required = $conditional['required'] ?? null;
        if (!is_array($required) || empty($required['enabled'])) {
            return false;
        }

        $rules = $required['rules'] ?? [];
        if (!is_array($rules) || $rules === []) {
            return false;
        }

        return self::rulesMatch($rules, self::normalizeMatch($required['match'] ?? self::MATCH_ALL), $values);
    }

    /**
     * Field handles referenced by a field's conditional rules (visibility +
     * required). Used for save-time cycle/self-reference/dangling detection.
     *
     * @param array<string, mixed> $config
     * @return string[]
     */
    public static function referencedFields(array $config): array
    {
        $conditional = $config['conditional'] ?? null;
        if (!is_array($conditional)) {
            return [];
        }

        $handles = [];
        foreach ([$conditional['rules'] ?? [], ($conditional['required']['rules'] ?? [])] as $ruleSet) {
            if (!is_array($ruleSet)) {
                continue;
            }
            foreach ($ruleSet as $rule) {
                if (is_array($rule) && isset($rule['field']) && $rule['field'] !== '') {
                    $handles[] = (string) $rule['field'];
                }
            }
        }

        return array_values(array_unique($handles));
    }

    /**
     * Combine a rule set under AND (all) / OR (any).
     *
     * @param array<int, mixed> $rules
     * @param array<string, mixed> $values
     */
    private static function rulesMatch(array $rules, string $match, array $values): bool
    {
        $results = [];
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            $results[] = self::evaluateRule($rule, $values);
        }

        if ($results === []) {
            return true;
        }

        return $match === self::MATCH_ANY
            ? in_array(true, $results, true)
            : !in_array(false, $results, true);
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $values
     */
    private static function evaluateRule(array $rule, array $values): bool
    {
        $handle = isset($rule['field']) ? (string) $rule['field'] : '';
        $operator = (string) ($rule['operator'] ?? 'eq');
        $expected = $rule['value'] ?? '';
        $actual = $values[$handle] ?? null;

        return self::compare($operator, $actual, $expected);
    }

    /**
     * Apply a single operator. Unknown operators never match.
     */
    public static function compare(string $operator, mixed $actual, mixed $expected): bool
    {
        switch ($operator) {
            case 'empty':
                return self::isEmpty($actual);
            case 'notEmpty':
                return !self::isEmpty($actual);
            case 'eq':
                return self::eq($actual, $expected);
            case 'neq':
                return !self::eq($actual, $expected);
            case 'contains':
                return self::contains($actual, $expected);
            case 'gt':
                $a = self::comparable($actual);
                $b = self::comparable($expected);
                return $a !== null && $b !== null && $a > $b;
            case 'lt':
                $a = self::comparable($actual);
                $b = self::comparable($expected);
                return $a !== null && $b !== null && $a < $b;
            default:
                return false;
        }
    }

    /** Clamp a posted/stored match mode to the supported set, defaulting to MATCH_ALL. */
    public static function normalizeMatch(mixed $match): string
    {
        return $match === self::MATCH_ANY ? self::MATCH_ANY : self::MATCH_ALL;
    }

    /**
     * Empty = null, '', false, or an array containing only empty values.
     * Note '0' is NOT empty — it is a legitimate value (e.g. a "No" option).
     */
    private static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === false) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::isEmpty($item)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }

    /**
     * String-normalised equality. For multi-value (array) actuals — e.g. a
     * checkbox group — equality holds when the expected value is one of the
     * selected values.
     */
    private static function eq(mixed $actual, mixed $expected): bool
    {
        $exp = self::scalarString($expected);

        if (is_array($actual)) {
            foreach ($actual as $item) {
                if (self::scalarString($item) === $exp) {
                    return true;
                }
            }
            return false;
        }

        return self::scalarString($actual) === $exp;
    }

    /**
     * For arrays: option membership. For strings: substring match.
     */
    private static function contains(mixed $actual, mixed $expected): bool
    {
        $exp = self::scalarString($expected);
        if ($exp === '') {
            return false;
        }

        if (is_array($actual)) {
            foreach ($actual as $item) {
                if (self::scalarString($item) === $exp) {
                    return true;
                }
            }
            return false;
        }

        return str_contains(self::scalarString($actual), $exp);
    }

    /**
     * Coerce a value to a comparable number for gt/lt: numeric strings become
     * floats; otherwise a parseable date/time string becomes its timestamp.
     */
    private static function comparable(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (is_string($value) && $value !== '') {
            if (is_numeric($value)) {
                return (float) $value;
            }
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return (float) $timestamp;
            }
        }

        return null;
    }

    private static function scalarString(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }
        if ($value === true) {
            return '1';
        }
        if (is_array($value)) {
            return '';
        }

        return (string) $value;
    }
}

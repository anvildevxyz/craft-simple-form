<?php

namespace fabianhaef\simpleform\helpers;

/**
 * Logic jumps (#245): page-level branching for multi-step + conversational
 * forms. A field may carry `config.jumps`, an ordered list of
 * `{operator, value, target}` rules — "when this field's answer matches, jump to
 * the step/screen that holds field `target`". Jumps only ever go forward (a
 * target must be a later step), which makes circular routes impossible by
 * construction; backward/dangling targets are dropped here and rejected at save.
 *
 * Pure and framework-agnostic, sharing {@see ConditionalEvaluator::compare()}'s
 * operator semantics. The front-end navigator (SF.jumps) mirrors `next()` and
 * `reachable()` exactly, so the screen the visitor sees and the steps the server
 * validates always agree; both are covered by parallel tests.
 *
 * A "step rules" array is the resolved, render-ready form: one entry per
 * step/screen index, each a list of `{field, operator, value, to}` where `to`
 * is the destination step index.
 */
final class JumpResolver
{
    /**
     * The rendered step/screen sequence as per-step input-field-handle lists —
     * the single source of truth shared by the render (for the navigator) and
     * the server (for the reachable-step replay), so both index steps the same
     * way. Conversational forms use the screen sequence; others use the pages.
     *
     * @param list<array<string, mixed>> $fields resolved field rows (with `name`, `config`, `type`)
     * @param list<string> $layoutTypes the non-input (presentational) type handles
     * @return list<list<string>> step index => field handles on it
     */
    public static function stepSequence(array $fields, bool $conversational, array $layoutTypes): array
    {
        $steps = FormSteps::group($fields);

        if ($conversational) {
            $screens = FormScreens::conversational($fields, $steps, $layoutTypes);
            return array_map(static function(array $screenRows): array {
                $handles = [];
                foreach ($screenRows as $row) {
                    foreach ($row as $field) {
                        $handles[] = (string) $field['name'];
                    }
                }
                return $handles;
            }, $screens);
        }

        return array_map(
            static fn(array $stepFields): array => array_map(static fn(array $f): string => (string) $f['name'], $stepFields),
            $steps,
        );
    }

    /**
     * Build the per-step jump rules from the rendered step/screen sequence and
     * the fields' configs. `$sequence[i]` is the list of input field handles on
     * step `i`; only forward jumps to an existing handle survive.
     *
     * @param list<list<string>> $sequence step index => the input field handles on it
     * @param array<string, array<string, mixed>> $configByHandle field handle => its config
     * @return list<list<array{field: string, operator: string, value: mixed, to: int}>>
     */
    public static function buildStepRules(array $sequence, array $configByHandle): array
    {
        $stepOf = [];
        foreach ($sequence as $i => $handles) {
            foreach ($handles as $handle) {
                $stepOf[$handle] = $i;
            }
        }

        $stepRules = [];
        foreach ($sequence as $i => $handles) {
            $rules = [];
            foreach ($handles as $handle) {
                foreach (self::jumpsOf($configByHandle[$handle] ?? []) as $jump) {
                    $target = (string) ($jump['target'] ?? '');
                    if (!isset($stepOf[$target]) || $stepOf[$target] <= $i) {
                        // Forward-only + existing target; anything else is dropped
                        // (and refused at save by validateJumps()).
                        continue;
                    }
                    $rules[] = [
                        'field' => $handle,
                        'operator' => (string) ($jump['operator'] ?? 'eq'),
                        'value' => $jump['value'] ?? '',
                        'to' => $stepOf[$target],
                    ];
                }
            }
            $stepRules[] = $rules;
        }

        return $stepRules;
    }

    /**
     * The next step index from `$current` given the answers: the first matching
     * jump rule's target, or the next sequential step when none match.
     *
     * @param list<list<array{field: string, operator: string, value: mixed, to: int}>> $stepRules
     * @param array<string, mixed> $values posted values keyed by field handle
     */
    public static function next(array $stepRules, int $current, array $values): int
    {
        foreach ($stepRules[$current] ?? [] as $rule) {
            if (ConditionalEvaluator::compare((string) $rule['operator'], $values[$rule['field']] ?? null, $rule['value'] ?? '')) {
                return (int) $rule['to'];
            }
        }

        return $current + 1;
    }

    /**
     * The set of step indices reached by replaying the jump path from step 0 for
     * the given answers. Forward-only jumps guarantee termination.
     *
     * @param list<list<array{field: string, operator: string, value: mixed, to: int}>> $stepRules
     * @param array<string, mixed> $values
     * @return list<int> the visited step indices, ascending
     */
    public static function reachable(array $stepRules, int $count, array $values): array
    {
        $visited = [];
        $i = 0;
        while ($i < $count) {
            $visited[$i] = true;
            $nextIndex = self::next($stepRules, $i, $values);
            // Defensive: never go backward/stall (forward-only already ensures it).
            $i = $nextIndex > $i ? $nextIndex : $i + 1;
        }

        return array_map('intval', array_keys($visited));
    }

    /**
     * The field handles a field's jumps target — for save-time dangling/forward
     * validation (mirrors {@see ConditionalEvaluator::referencedFields()}).
     *
     * @param array<string, mixed> $config
     * @return list<string>
     */
    public static function referencedTargets(array $config): array
    {
        $targets = [];
        foreach (self::jumpsOf($config) as $jump) {
            $target = (string) ($jump['target'] ?? '');
            if ($target !== '') {
                $targets[] = $target;
            }
        }

        return array_values(array_unique($targets));
    }

    /**
     * Normalise a config's `jumps` to a list of rule arrays (tolerant of legacy
     * or malformed shapes).
     *
     * @param array<string, mixed> $config
     * @return list<array<string, mixed>>
     */
    private static function jumpsOf(array $config): array
    {
        $jumps = $config['jumps'] ?? null;
        if (!is_array($jumps)) {
            return [];
        }

        $out = [];
        foreach ($jumps as $jump) {
            if (is_array($jump) && ($jump['target'] ?? '') !== '') {
                $out[] = $jump;
            }
        }

        return $out;
    }
}

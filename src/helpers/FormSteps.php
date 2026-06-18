<?php

namespace fabianhaef\simpleform\helpers;

/**
 * Groups a form's resolved field set into ordered steps (pages) by each field's
 * `config.page` (1-based; default 1). A form with fields on a single page is not
 * multi-step, so existing forms render exactly as before.
 */
final class FormSteps
{
    /**
     * @param array<int, array<string, mixed>> $fields resolved field rows
     * @return list<list<array<string, mixed>>> steps in page order, each a list of fields (original order kept)
     */
    public static function group(array $fields): array
    {
        $byPage = [];
        foreach ($fields as $field) {
            $byPage[self::pageOf($field)][] = $field;
        }
        ksort($byPage, SORT_NUMERIC);

        return array_values($byPage);
    }

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    public static function isMultiStep(array $fields): bool
    {
        return count(self::group($fields)) > 1;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function pageOf(array $field): int
    {
        $config = $field['config'] ?? [];
        $page = is_array($config) ? ($config['page'] ?? 1) : 1;
        return (is_numeric($page) && (int) $page >= 1) ? (int) $page : 1;
    }
}

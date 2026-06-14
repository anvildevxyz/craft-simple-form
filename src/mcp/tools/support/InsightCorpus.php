<?php

namespace fabianhaef\simpleform\mcp\tools\support;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;

/**
 * Shared helpers for the AI-insight tools (#67): resolving which form fields
 * carry free text versus closed options, and shaping submission text into the
 * corpora the CLIENT model reasons over.
 *
 * These tools deliberately do NO natural-language reasoning themselves — they
 * shape and return text plus cheap server-side signals; the client model
 * summarises / categorises / judges.
 */
final class InsightCorpus
{
    /** Field types whose stored values are open-ended free text. */
    public const FREE_TEXT_TYPES = ['text', 'textarea', 'email'];

    /** Field types whose stored values are a closed set of options (groupable). */
    public const OPTION_TYPES = ['select', 'radio', 'checkbox'];

    /**
     * Map of field handle => type for a form, from its resolved field set
     * (reuses the same field resolution the tools/resources use).
     *
     * @return array<string, string>
     */
    public static function fieldTypes(Form $form): array
    {
        $types = [];
        foreach ($form->getFields() as $row) {
            $handle = (string)($row['name'] ?? '');
            if ($handle !== '') {
                $types[$handle] = (string)($row['type'] ?? '');
            }
        }

        return $types;
    }

    /**
     * The free-text field handles for a form. When the form schema can't be
     * resolved (no form filter), returns null so callers fall back to treating
     * every string value as text.
     *
     * @param array<string, string> $fieldTypes
     * @return list<string>
     */
    public static function freeTextHandles(array $fieldTypes): array
    {
        $handles = [];
        foreach ($fieldTypes as $handle => $type) {
            if (in_array($type, self::FREE_TEXT_TYPES, true)) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * Extract the free-text snippets from one submission. When $handles is empty
     * (no resolved schema), every scalar string value is treated as text.
     *
     * @param list<string> $handles
     * @return array<string, string> handle => text value (non-empty only)
     */
    public static function textValues(Submission $submission, array $handles): array
    {
        $data = $submission->data ?? [];
        $out = [];
        foreach ($data as $handle => $value) {
            if ($handles !== [] && !in_array($handle, $handles, true)) {
                continue;
            }
            if (is_array($value)) {
                $value = implode(' ', array_map('strval', $value));
            }
            $text = trim((string)$value);
            if ($text !== '') {
                $out[(string)$handle] = $text;
            }
        }

        return $out;
    }
}

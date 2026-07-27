<?php

namespace anvildev\simpleform\integrations\support;

use Craft;
use craft\helpers\StringHelper;

/**
 * Read helpers for the {@see \anvildev\simpleform\integrations\CraftElementIntegration}
 * settings UI: section / entry-type / user-group option lists and a compact,
 * human-readable summary of an element's validation errors for the dispatch log.
 *
 * @phpstan-type SelectOption array{label: string, value: string}
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class ElementMapping
{
    /**
     * Section UID => name options for the target-section dropdown.
     *
     * @return list<SelectOption>
     */
    public static function sectionOptions(): array
    {
        // Single sections already hold their one entry; create-from-submission
        // only makes sense for channels/structures.
        return array_values(array_map(
            static fn($section) => ['label' => (string) $section->name, 'value' => (string) $section->uid],
            array_filter(
                Craft::$app->getEntries()->getAllSections(),
                static fn($section) => $section->type !== 'single',
            ),
        ));
    }

    /**
     * Entry-type UID => name options across all sections, prefixed with the
     * section name so the dependent dropdown stays unambiguous.
     *
     * @return list<array{label: string, value: string, data: array{section: string}}>
     */
    public static function entryTypeOptions(): array
    {
        $out = [];
        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            if ($section->type === 'single') {
                continue;
            }
            foreach ($section->getEntryTypes() as $entryType) {
                $out[] = [
                    'label' => (string) $entryType->name,
                    'value' => (string) $entryType->uid,
                    'data' => ['section' => (string) $section->uid],
                ];
            }
        }
        return $out;
    }

    /**
     * User-group UID => name options for the target-group dropdown.
     *
     * @return list<SelectOption>
     */
    public static function userGroupOptions(): array
    {
        return array_map(
            static fn($group) => ['label' => (string) $group->name, 'value' => (string) $group->uid],
            Craft::$app->getUserGroups()->getAllGroups(),
        );
    }

    /**
     * Flatten an element's `getErrors()` into a single readable line for the
     * dispatch-log message, e.g. `Email: Email "x" has already been taken.`.
     *
     * @param array<string, array<int, string>> $errors
     */
    public static function summariseErrors(array $errors): string
    {
        $parts = [];
        foreach ($errors as $attribute => $messages) {
            $label = StringHelper::titleize((string) $attribute);
            foreach ((array) $messages as $message) {
                $parts[] = $label . ': ' . (string) $message;
            }
        }

        $summary = $parts === []
            ? Craft::t('simple-form', 'The element could not be saved.')
            : implode(' ', $parts);

        return StringHelper::safeTruncate($summary, 900);
    }
}

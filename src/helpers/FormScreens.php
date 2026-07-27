<?php

namespace anvildev\simpleform\helpers;

/**
 * Derives the screen sequence for conversational render mode (#239): one screen
 * per question, each screen a list of layout rows (so the existing field/row
 * markup renders unchanged and the multi-step navigator drives it).
 *
 * Granularity:
 *  - An explicitly authored multi-step form (more than one page) renders each
 *    page as one screen group — the author's pagination is respected.
 *  - Otherwise each input field is its own screen; presentational layout blocks
 *    (heading/divider/html) attach to the adjacent question's screen — a leading
 *    block introduces the next question, a trailing block joins the last screen.
 */
final class FormScreens
{
    /**
     * @param list<array<string, mixed>> $fields resolved field rows, in order
     * @param list<list<array<string, mixed>>> $steps the page grouping ({@see FormSteps::group()})
     * @param list<string> $layoutTypes the non-input (presentational) type handles
     * @return list<list<list<array<string, mixed>>>> screens → rows → fields
     */
    public static function conversational(array $fields, array $steps, array $layoutTypes): array
    {
        // Authored multi-page → one screen per page (respect the pagination).
        if (count($steps) > 1) {
            return array_map(static fn(array $stepFields): array => FormRows::group($stepFields), $steps);
        }

        // Single page → one input field per screen; layout blocks ride along with
        // the next question (or the last screen when trailing).
        $screens = [];
        $pending = [];
        foreach ($fields as $field) {
            $pending[] = $field;
            if (!in_array($field['type'] ?? '', $layoutTypes, true)) {
                $screens[] = $pending;
                $pending = [];
            }
        }
        if ($pending !== []) {
            if ($screens === []) {
                $screens[] = $pending;
            } else {
                $last = count($screens) - 1;
                $screens[$last] = array_merge($screens[$last], $pending);
            }
        }

        return array_map(static fn(array $screenFields): array => FormRows::group($screenFields), $screens);
    }
}

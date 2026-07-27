<?php

namespace anvildev\simpleform\mcp\tools\support;

/**
 * Shared `confirm: true` gate for the destructive MCP tools (delete form/field),
 * so the refusal contract — schema property, required flag, and refusal payload
 * — stays identical across every destructive tool and any future ones.
 */
final class ConfirmGate
{
    /**
     * The `confirm` boolean input-schema property. Merge into a tool's
     * `properties` and add `'confirm'` to its `required` list.
     *
     * @return array<string, mixed>
     */
    public static function schemaProperty(): array
    {
        return [
            'confirm' => [
                'type' => 'boolean',
                'description' => 'Must be true to actually delete. The call is refused without it.',
            ],
        ];
    }

    /**
     * The refusal payload when `confirm` isn't true, or null when the caller
     * confirmed. $subject names the thing being deleted, e.g. `'a form'`.
     *
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>|null
     */
    public static function refusalUnlessConfirmed(array $arguments, string $subject): ?array
    {
        if (($arguments['confirm'] ?? false) === true) {
            return null;
        }

        return [
            'isError' => true,
            'error' => 'Refused: deleting ' . $subject . ' is destructive. Pass "confirm": true to proceed.',
        ];
    }
}

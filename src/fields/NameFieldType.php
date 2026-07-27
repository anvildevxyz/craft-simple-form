<?php

namespace anvildev\simpleform\fields;

/**
 * The composite Name field: prefix / first / middle / last / suffix sub-inputs,
 * each individually toggleable. Defaults to first + last enabled (both primary,
 * so the field-level `required` shorthand makes them mandatory); the rest off.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class NameFieldType extends CompositeFieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'name';
    }

    public static function getLabel(): string
    {
        return 'Name';
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @return array<string, CompositeSubField>
     */
    protected static function subFieldDefs(): array
    {
        return [
            'prefix' => new CompositeSubField('Prefix', enabledByDefault: false),
            'first' => new CompositeSubField('First name', enabledByDefault: true, primary: true),
            'middle' => new CompositeSubField('Middle name', enabledByDefault: false),
            'last' => new CompositeSubField('Last name', enabledByDefault: true, primary: true),
            'suffix' => new CompositeSubField('Suffix', enabledByDefault: false),
        ];
    }
}

<?php

namespace anvildev\simpleform\tests\fixtures;

use anvildev\simpleform\fields\FieldType;

/**
 * A minimal custom field type used by DeveloperEventsTest to verify the
 * EVENT_REGISTER_FIELD_TYPES extension point.
 */
class StubFieldType extends FieldType
{
    public static function getType(): string
    {
        return 'stub_dx';
    }

    public static function getLabel(): string
    {
        return 'Stub DX Field';
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        return sprintf('<input type="text" name="%s">', htmlspecialchars($name, ENT_QUOTES));
    }
}

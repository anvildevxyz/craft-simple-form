<?php

namespace modules\simpleform\examples;

use fabianhaef\simpleform\fields\FieldType;

/**
 * Example custom field type: a native colour picker that stores a #RRGGBB hex
 * value. Demonstrates the three required methods (getType / getLabel /
 * renderInput) plus value validation.
 *
 * Register it from your plugin/module init():
 *
 *   \yii\base\Event::on(
 *       \fabianhaef\simpleform\Plugin::class,
 *       \fabianhaef\simpleform\Plugin::EVENT_REGISTER_FIELD_TYPES,
 *       fn($e) => $e->types[] = \modules\simpleform\examples\ColorField::class,
 *   );
 *
 * Scaffold your own with:  php craft simple-form/make/field-type
 */
class ColorField extends FieldType
{
    public static function getType(): string
    {
        // Stable machine handle stored on the field row.
        return 'color';
    }

    public static function getLabel(): string
    {
        // Shown in the CP field-type picker.
        return 'Colour';
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $hex = is_string($value) && $value !== '' ? $value : '#000000';

        return sprintf(
            '<input type="color" name="%s" value="%s">',
            htmlspecialchars($name, ENT_QUOTES),
            htmlspecialchars($hex, ENT_QUOTES),
        );
    }

    /**
     * Server-side validation runs on every submit and is authoritative — never
     * trust the browser's `type=color` constraint alone.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = [];
        $str = trim((string) $value);

        if ($str === '') {
            if (!empty($this->config['required'])) {
                $errors[] = 'Please choose a colour.';
            }
            return $errors;
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $str) !== 1) {
            $errors[] = 'Enter a valid hex colour, e.g. #3366ff.';
        }

        return $errors;
    }
}

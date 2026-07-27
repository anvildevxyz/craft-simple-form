<?php

namespace anvildev\simpleform\fields;

use Craft;

/**
 * Base for the multi-part composite field types (Name, Address): a single field
 * that renders several labelled sub-inputs, stores their values as an associative
 * sub-part map, validates each part, and flattens to one export column per part.
 *
 * Subclasses declare an ordered set of sub-fields via {@see self::subFieldDefs()};
 * the render loop, posted-value coercion, per-sub validation, and the flatten
 * helpers all live here so Name and Address don't duplicate the machinery.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
abstract class CompositeFieldType extends FieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The ordered sub-field definitions for this composite, keyed by stable sub
     * key. Each value is {@see CompositeSubField}: a default translatable label,
     * an input kind (`text` or `select`), whether it is enabled by default, and
     * whether it counts as a "primary" sub-field (the ones the field-level
     * `required` shorthand makes mandatory).
     *
     * @return array<string, CompositeSubField>
     */
    abstract protected static function subFieldDefs(): array;

    /**
     * The enabled sub-fields for this field instance, in declaration order,
     * resolved against the per-field `subFields` config overlay. Each row carries
     * the effective label, input kind, and required flag. Select options are
     * fetched lazily at render/validate time via {@see self::optionsFor()} so
     * resolving the set never forces a (locale-dependent) country lookup.
     *
     * @return array<string, array{label: string, kind: string, required: bool}>
     */
    public function enabledSubFields(): array
    {
        $overlay = $this->subFieldConfig();
        $fieldRequired = (bool) ($this->config['required'] ?? false);

        $resolved = [];
        foreach (static::subFieldDefs() as $key => $def) {
            $row = $overlay[$key] ?? [];

            // A sub-field defaults to its declared enabled-ness; an explicit
            // `enabled` in config wins.
            $enabled = array_key_exists('enabled', $row) ? (bool) $row['enabled'] : $def->enabledByDefault;
            if (!$enabled) {
                continue;
            }

            // Per-sub `required` wins; otherwise the field-level shorthand makes
            // the primary sub-fields required.
            $required = array_key_exists('required', $row)
                ? (bool) $row['required']
                : ($fieldRequired && $def->primary);

            $label = isset($row['label']) && trim((string) $row['label']) !== ''
                ? (string) $row['label']
                : Craft::t('simple-form', $def->label);

            $resolved[$key] = [
                'label' => $label,
                'kind' => $def->kind,
                'required' => $required,
            ];
        }

        return $resolved;
    }

    /**
     * Options for one `select`-kind sub-field (only `country` in v1), fetched
     * lazily so the country list is sourced from Craft only when actually
     * rendering or validating that control.
     *
     * @return array<string, string>
     */
    public function optionsFor(string $key): array
    {
        return $this->subFieldOptions($key);
    }

    /**
     * Validate the composite's posted value (an associative sub-part map). A
     * non-array value is rejected outright; otherwise each enabled sub-field is
     * required/length/membership-checked and every error names its sub-label so
     * the visitor knows which part failed.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $subFields = $this->enabledSubFields();

        // A composite must post an associative array; a scalar/null is only OK
        // when nothing is required (an entirely-optional, untouched field).
        if (!is_array($value)) {
            $value = [];
        }

        $errors = [];
        foreach ($subFields as $key => $sub) {
            $raw = $value[$key] ?? null;
            $part = is_scalar($raw) ? trim((string) $raw) : '';

            if ($sub['required'] && $part === '') {
                $errors[] = Craft::t('simple-form', '{label} is required.', ['label' => $sub['label']]);
                continue;
            }

            if ($part === '') {
                continue;
            }

            $errors = array_merge($errors, $this->validateLength($part));

            if ($sub['kind'] === CompositeSubField::KIND_SELECT) {
                $options = $this->optionsFor($key);
                if (!isset($options[$part])) {
                    $errors[] = Craft::t('simple-form', '{label} is not a valid choice.', ['label' => $sub['label']]);
                }
            }
        }

        return $errors;
    }

    /**
     * Render the composite as a `<fieldset>` of labelled sub-inputs, each with a
     * unique id and an explicit `<label for>`. Sub-inputs post as one associative
     * array under the field name (`field_<id>[<key>]`), so no stray bare `name`
     * collides inside a `fullPageForm`.
     */
    /**
     * Markup rendered inside the composite `<fieldset>`, before the sub-inputs.
     * The base composite emits nothing; {@see AddressFieldType} overrides this to
     * inject the opt-in autocomplete search box.
     */
    protected function beforeSubFields(string $name): string
    {
        return '';
    }

    public function renderInput(string $name, mixed $value = null): string
    {
        $values = is_array($value) ? $value : [];
        // Point the fieldset at the group label the renderer actually emits
        // (field.twig renders the label span with id "<name>-label").
        $labelId = htmlspecialchars($name) . '-label';

        $html = sprintf('<fieldset class="sf-composite" aria-labelledby="%s">', $labelId);

        // Optional pre-sub-field markup (e.g. the Address autocomplete search box,
        // #250). The base composite has none.
        $html .= $this->beforeSubFields($name);

        $escapedName = htmlspecialchars($name);
        foreach ($this->enabledSubFields() as $key => $sub) {
            $escapedKey = htmlspecialchars($key);
            $id = $escapedName . '-' . $escapedKey;
            $inputName = sprintf('%s[%s]', $escapedName, $escapedKey);
            $subValue = is_scalar($values[$key] ?? null) ? (string) $values[$key] : '';
            $required = $sub['required'] ? ' required' : '';

            $html .= '<div class="sf-subfield">';
            $html .= sprintf('<label for="%s">%s</label>', $id, htmlspecialchars($sub['label']));

            if ($sub['kind'] === CompositeSubField::KIND_SELECT) {
                $html .= sprintf('<select id="%s" name="%s"%s class="fullwidth">', $id, $inputName, $required);
                $html .= '<option value=""></option>';
                foreach ($this->optionsFor($key) as $optValue => $optLabel) {
                    $selected = $subValue === (string) $optValue ? ' selected' : '';
                    $html .= sprintf(
                        '<option value="%s"%s>%s</option>',
                        htmlspecialchars((string) $optValue),
                        $selected,
                        htmlspecialchars((string) $optLabel)
                    );
                }
                $html .= '</select>';
            } else {
                $html .= sprintf(
                    '<input type="text" id="%s" name="%s" value="%s"%s class="text fullwidth">',
                    $id,
                    $inputName,
                    htmlspecialchars($subValue),
                    $required
                );
            }

            $html .= '</div>';
        }

        $html .= '</fieldset>';

        return $html;
    }

    /**
     * Limit a posted sub-part map to this field's enabled sub-field keys so a
     * crafted POST cannot inject keys the field never rendered (defense-in-depth,
     * mirroring the choice fields' option-membership check). Returns the cleaned,
     * order-stable map that is what gets stored as the submission value.
     *
     * @param mixed $value the raw posted value
     * @return array<string, string>
     */
    public function serializeValue(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        $clean = [];
        foreach ($this->enabledSubFields() as $key => $sub) {
            $raw = $value[$key] ?? null;
            $clean[$key] = is_scalar($raw) ? (string) $raw : '';
        }

        return $clean;
    }

    /**
     * Composites are never a choice group: the `<fieldset>` legend (not a single
     * `<label for>`) labels the group, so the front-end renderer must not point a
     * group `<label for>` at a single missing control.
     */
    public function isChoiceGroup(): bool
    {
        return true;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * The per-field `subFields` config overlay (enabled/required/label per sub
     * key), normalized to an array. Empty when the field uses pure defaults.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function subFieldConfig(): array
    {
        $overlay = $this->config['subFields'] ?? [];
        if (is_string($overlay)) {
            $overlay = json_decode($overlay, true) ?? [];
        }

        if (!is_array($overlay)) {
            return [];
        }

        $result = [];
        foreach ($overlay as $key => $row) {
            if (is_object($row)) {
                $row = (array) $row;
            }
            if (is_array($row)) {
                $result[(string) $key] = $row;
            }
        }

        return $result;
    }

    /**
     * Options for a `select`-kind sub-field (only `country` in v1). Overridden by
     * the Address type; the default has none.
     *
     * @return array<string, string>
     */
    protected function subFieldOptions(string $key): array
    {
        return [];
    }
}

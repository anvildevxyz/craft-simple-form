<?php

namespace anvildev\simpleform\fields;

use Craft;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;

/**
 * Shared base for the element-relation field types (entry, category, tag, user,
 * asset). A relation field lets a visitor select one or more live Craft elements
 * of a single element type, constrained to a configured set of sources
 * (section/group/volume handles, or `*` for any source of that type).
 *
 * The chosen element **ids** are stored in the submission data (single-select
 * still stores a one-element list for a uniform read path). Validation is
 * entirely server-side: every posted id must belong to the configured allowed
 * source — a forged id referencing a disallowed, soft-deleted, or non-existent
 * element is rejected.
 *
 * Config (per field, in the JSON `config` column):
 *  - `sources` — list of allowed source handles, or `'*'` for any source.
 *  - `multiple` (bool) — single vs. multi select.
 *  - `limit` (int|null) — max selectable when `multiple`.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
abstract class ElementRelationFieldType extends FieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * The fully-qualified Craft element class this relation selects.
     *
     * @return class-string<ElementInterface>
     */
    abstract public static function elementType(): string;

    /**
     * Whether this field allows selecting more than one element.
     */
    public function isMultiple(): bool
    {
        return (bool) ($this->config['multiple'] ?? false);
    }

    /**
     * The maximum number of selectable elements when {@see self::isMultiple()},
     * or null for no limit.
     */
    public function limit(): ?int
    {
        $limit = $this->config['limit'] ?? null;
        return (is_numeric($limit) && (int) $limit > 0) ? (int) $limit : null;
    }

    /**
     * The configured allowed source handles, or `['*']` for any source of this
     * element type. Always a non-empty list (defaults to `['*']`).
     *
     * @return list<string>
     */
    public function sources(): array
    {
        $raw = $this->config['sources'] ?? null;
        if (is_string($raw)) {
            $raw = $raw === '' ? [] : [$raw];
        }
        if (!is_array($raw)) {
            return ['*'];
        }

        $out = [];
        foreach ($raw as $source) {
            $source = trim((string) $source);
            if ($source !== '') {
                $out[] = $source;
            }
        }

        if ($out === [] || in_array('*', $out, true)) {
            return ['*'];
        }

        return array_values(array_unique($out));
    }

    /**
     * Multi-select relations render a choice group (checkboxes), each option
     * needing its own id + <label for>; single-select renders one <select>.
     */
    public function isChoiceGroup(): bool
    {
        return $this->isMultiple();
    }

    /**
     * A multi-select relation stores an id list; single-select still stores a
     * one-element list internally, but {@see self::renderInput()} takes a bare
     * scalar id there, so only the multi case accepts a list value from the
     * query string (#316's array-DoS fix).
     */
    public function acceptsListValue(): bool
    {
        return $this->isMultiple();
    }

    /**
     * Build the element query constrained to the configured allowed sources,
     * scoped to live/enabled elements only so the public option list never leaks
     * elements a visitor shouldn't see.
     *
     * @param bool $crossSite when true, search every site (`siteId('*')`) for
     *                        membership checks; when false, resolve for the
     *                        current site so rendered option titles are correct.
     */
    public function allowedElementQuery(bool $crossSite = false): ElementQueryInterface
    {
        /** @var class-string<ElementInterface> $elementClass */
        $elementClass = static::elementType();
        /** @var ElementQueryInterface $query */
        $query = $elementClass::find();

        $this->applySources($query);

        if ($crossSite) {
            $query->siteId('*');
        }

        return $query;
    }

    /**
     * The set of element ids that belong to the configured allowed sources,
     * resolved across every site so a form on a non-primary site still validates
     * ids that live on another site.
     *
     * @return list<int>
     */
    public function allowedIds(): array
    {
        /** @var list<int> $ids */
        $ids = $this->allowedElementQuery(true)->ids();
        return array_map('intval', $ids);
    }

    /**
     * Validate a posted selection: required, membership in the allowed source,
     * single-vs-multi count, and the multi-select limit.
     *
     * @return string[]
     */
    public function validate(mixed $value): array
    {
        $errors = parent::validate($value);

        $ids = $this->normalizeIds($value);
        if ($ids === []) {
            // Required-ness is enforced by parent::validate(); nothing else to do
            // for an empty selection.
            return $errors;
        }

        $multiple = $this->isMultiple();
        if (!$multiple && count($ids) > 1) {
            $errors[] = Craft::t('simple-form', 'Only one option may be selected.');
        }

        $limit = $this->limit();
        if ($multiple && $limit !== null && count($ids) > $limit) {
            $errors[] = Craft::t('simple-form', 'Please select no more than {limit} options.', ['limit' => $limit]);
        }

        // Membership: every posted id must belong to the allowed source. A keyed
        // isset() against the allowed-id set is the element-id analogue of the
        // choice-field option-membership check.
        $allowed = array_fill_keys($this->allowedIds(), true);
        foreach ($ids as $id) {
            if (!isset($allowed[$id])) {
                $errors[] = Craft::t('simple-form', 'Please select a valid option.');
                break;
            }
        }

        return $errors;
    }

    /**
     * Render the no-JS-safe public control: a `<select>` for single-select, or a
     * checkbox group for multi-select, populated from the allowed source with the
     * element title as the option label and the element id as the value.
     */
    public function renderInput(string $name, mixed $value = null): string
    {
        $selected = array_fill_keys($this->normalizeIds($value), true);
        $options = $this->optionList();

        if ($this->isMultiple()) {
            return $this->renderCheckboxes($name, $options, $selected);
        }

        return $this->renderSelect($name, $options, $selected);
    }

    /**
     * Resolve a stored id list to display labels keyed by id, for submission
     * detail and export. Surviving disabled/other-site elements via
     * `status(null)` + `siteId('*')`; missing elements fall back to `#<id>`.
     *
     * @param list<int> $ids
     * @return array<int, string>
     */
    public function labelsForIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var class-string<ElementInterface> $elementClass */
        $elementClass = static::elementType();
        /** @var array<int, ElementInterface> $elements */
        $elements = $elementClass::find()
            ->id($ids)
            ->siteId('*')
            ->status(null)
            ->indexBy('id')
            ->all();

        $labels = [];
        foreach ($ids as $id) {
            $element = $elements[$id] ?? null;
            $labels[$id] = $element !== null
                ? (string) $element
                : Craft::t('simple-form', '(deleted #{id})', ['id' => $id]);
        }

        return $labels;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * Constrain an element query to the configured allowed sources. The source
     * parameter name differs per element type (section/group/volume), so each
     * concrete type implements this.
     */
    abstract protected function applySources(ElementQueryInterface $query): void;

    /**
     * The public option list (id => label) resolved for the current site so
     * titles render in the visitor's language. Disabled/other-site elements are
     * deliberately excluded — only intended, live elements are offered.
     *
     * @return array<int, string>
     */
    protected function optionList(): array
    {
        /** @var array<int, ElementInterface> $elements */
        $elements = $this->allowedElementQuery()->indexBy('id')->all();

        $options = [];
        foreach ($elements as $id => $element) {
            $options[(int) $id] = (string) $element;
        }

        return $options;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Normalize a posted value (a single id, an id list, or an array of stringy
     * ids) to a de-duplicated list of positive ints. Non-numeric/zero entries are
     * dropped so a crafted non-id value can't slip past membership.
     *
     * @return list<int>
     */
    private function normalizeIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $raw = is_array($value) ? $value : [$value];

        $ids = [];
        foreach ($raw as $entry) {
            if (is_numeric($entry) && (int) $entry > 0) {
                $ids[(int) $entry] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * @param array<int, string> $options id => label
     * @param array<int, true> $selected selected ids
     */
    private function renderSelect(string $name, array $options, array $selected): string
    {
        $attrs = $this->controlAttributes($name);
        $html = sprintf('<select %s class="fullwidth">', $attrs);
        if (!($this->config['required'] ?? false)) {
            $html .= '<option value="">' . htmlspecialchars(Craft::t('simple-form', '-- Select an option --')) . '</option>';
        }

        foreach ($options as $id => $label) {
            $isSelected = isset($selected[$id]) ? ' selected' : '';
            $html .= sprintf(
                '<option value="%d"%s>%s</option>',
                $id,
                $isSelected,
                htmlspecialchars($label),
            );
        }

        $html .= '</select>';
        return $html;
    }

    /**
     * @param array<int, string> $options id => label
     * @param array<int, true> $selected selected ids
     */
    private function renderCheckboxes(string $name, array $options, array $selected): string
    {
        $html = '<div class="checkbox-group">';
        $escapedName = htmlspecialchars($name);
        $i = 0;
        foreach ($options as $id => $label) {
            // Unique id per option + explicit <label for> (a11y); the group is
            // labelled via the field group's aria-labelledby.
            $optId = $escapedName . '-' . $i;
            $checked = isset($selected[$id]) ? ' checked' : '';
            $html .= sprintf(
                '<input type="checkbox" id="%s" name="%s[]" value="%d"%s> <label for="%s">%s</label><br>',
                $optId,
                $escapedName,
                $id,
                $checked,
                $optId,
                htmlspecialchars($label),
            );
            $i++;
        }
        $html .= '</div>';

        return $html;
    }
}

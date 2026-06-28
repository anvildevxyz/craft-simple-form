<?php

namespace anvildev\simpleform\fields;

/**
 * An immutable declaration of one sub-field within a composite field type: its
 * default (translatable, source) label, input kind, default enabled-ness, and
 * whether it is a "primary" part that the field-level `required` shorthand makes
 * mandatory.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
final class CompositeSubField
{
    // =========================================================================
    // Const Properties
    // =========================================================================

    public const KIND_TEXT = 'text';

    public const KIND_SELECT = 'select';

    // =========================================================================
    // Public Properties
    // =========================================================================

    /** @var string The default (source) translation-key label. */
    public string $label;

    /** @var string One of the `KIND_*` constants. */
    public string $kind;

    /** @var bool Whether this sub-field renders by default. */
    public bool $enabledByDefault;

    /** @var bool Whether the field-level `required` shorthand makes this required. */
    public bool $primary;

    // =========================================================================
    // Public Methods
    // =========================================================================

    public function __construct(
        string $label,
        string $kind = self::KIND_TEXT,
        bool $enabledByDefault = true,
        bool $primary = false,
    ) {
        $this->label = $label;
        $this->kind = $kind;
        $this->enabledByDefault = $enabledByDefault;
        $this->primary = $primary;
    }
}

<?php

namespace fabianhaef\simpleform\stencils;

/**
 * A built-in form starter: a translated display name/description, an ordered set
 * of fields in the field-builder/sync-item shape, and optional default
 * notifications. Stencils are pure data (no element rows, no project config);
 * {@see \fabianhaef\simpleform\services\FormCloneService::createFromStencil()}
 * instantiates one into a real {@see \fabianhaef\simpleform\elements\Form}.
 *
 * Notification recipients that read a form field declare their `recipient` as
 * the stencil's own field handle (e.g. the email field); the field is copied
 * verbatim, so the handle resolves against the new form's fields.
 *
 * @since 2.11.0
 * @author Fabian Haefliger
 */
class Stencil
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /** ASCII-safe handle used to look the stencil up and seed the new form's handle. */
    public string $handle = '';

    /** Translated display name shown in the "New form" menu and used as the form name. */
    public string $name = '';

    /** Translated one-line description shown in the picker. */
    public string $description = '';

    /**
     * Ordered field set in the sync-item shape consumed by
     * {@see \fabianhaef\simpleform\services\FieldSyncService::sync()}:
     * `{type, handle, label, required, helpText?, errorMessage?, config?}`.
     *
     * @var array<int,array<string,mixed>>
     */
    public array $fields = [];

    /**
     * Default notifications to attach, each as
     * `{name, recipientType, recipient, subject?, replyTo?, body?}`. A
     * `recipientType` of `field` references one of this stencil's field handles.
     *
     * @var array<int,array<string,mixed>>
     */
    public array $notifications = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (property_exists($this, $key)) {
                $this->$key = $value;
            }
        }
    }
}

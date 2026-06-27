<?php

namespace fabianhaef\simpleform\models;

use craft\base\Model;
use fabianhaef\simpleform\elements\Form;

/**
 * The outcome of a {@see \fabianhaef\simpleform\services\FormPortabilityService::import()}:
 * the recreated {@see Form} plus any non-fatal warnings (skipped sites, integrations
 * needing credentials, schema upgrades applied) the caller should surface (#139).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class ImportResult extends Model
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /** The recreated form element. */
    public ?Form $form = null;

    /**
     * Human-readable, already-translated warning messages.
     *
     * @var list<string>
     */
    public array $warnings = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Append a warning message to the result.
     */
    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
}

<?php

namespace fabianhaef\simpleform\integrations;

use fabianhaef\simpleform\elements\Submission;

/**
 * An outbound integration type (connector). Implementations are stateless
 * transformers: per-form config comes in, an HTTP/SDK call goes out, an
 * {@see IntegrationResult} comes back. New connectors are added by implementing
 * this interface and registering the class via
 * {@see \fabianhaef\simpleform\Plugin::EVENT_REGISTER_INTEGRATION_TYPES} — no
 * change to the submission path is required.
 */
interface IntegrationTypeInterface
{
    /** Stable machine handle stored in `simpleform_integrations.type` (e.g. `webhook`). */
    public static function handle(): string;

    /** Human-readable name shown in the CP integration picker. */
    public static function displayName(): string;

    /**
     * Settings-form HTML rendered in the CP when configuring an instance of this type.
     *
     * @param array<string, mixed> $settings the currently-saved settings
     */
    public function settingsHtml(array $settings): string;

    /**
     * Yii validation rules applied to this type's settings before save.
     *
     * @return array<int, mixed>
     */
    public function defineSettingsRules(): array;

    /**
     * Perform the outbound dispatch for a saved submission.
     *
     * @param array<string, mixed> $settings the saved, env-parsed settings
     */
    public function send(Submission $submission, array $settings): IntegrationResult;
}

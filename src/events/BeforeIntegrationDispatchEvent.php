<?php

namespace fabianhaef\simpleform\events;

use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\IntegrationModel;
use yii\base\Event;

/**
 * Fired from {@see \fabianhaef\simpleform\services\IntegrationsService::runOnce()}
 * before a single outbound integration dispatch is attempted. A handler can
 * adjust the resolved {@see self::$settings} (env vars already parsed) or skip
 * the dispatch entirely by setting {@see self::$send} to false:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_BEFORE_INTEGRATION_DISPATCH,
 *     function(BeforeIntegrationDispatchEvent $e): void {
 *         if ($e->submission->getStatus() === 'spam') {
 *             $e->send = false; // never forward spam downstream
 *         }
 *     }
 * );
 * ```
 *
 * A skipped dispatch is recorded as a successful no-op so it is not retried.
 *
 * @since 1.0.0
 */
class BeforeIntegrationDispatchEvent extends Event
{
    public IntegrationModel $integration;

    public Submission $submission;

    /**
     * The resolved connector settings (env vars parsed). Mutate to adjust the
     * dispatch; never logged in plaintext.
     *
     * @var array<string, mixed>
     */
    public array $settings = [];

    /** Set to false to skip this dispatch. */
    public bool $send = true;
}

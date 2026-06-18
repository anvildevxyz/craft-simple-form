<?php

namespace fabianhaef\simpleform\integrations;

use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\support\ApiConnector;

/**
 * Shared base for CRM connectors (HubSpot, Pipedrive) that create a
 * contact/lead/deal from a submission via a token-authenticated API. The
 * HTTP/email/mapping plumbing comes from {@see ApiConnector}.
 */
abstract class AbstractCrmIntegration implements IntegrationTypeInterface
{
    use ApiConnector;

    abstract public static function handle(): string;

    abstract public static function displayName(): string;

    abstract public function settingsHtml(array $settings): string;

    abstract public function send(Submission $submission, array $settings): IntegrationResult;

    public function defineSettingsRules(): array
    {
        return [
            [['apiToken'], 'required'],
            [['apiToken'], 'string'],
        ];
    }
}

<?php

namespace anvildev\simpleform\integrations;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\support\ApiConnector;

/**
 * Shared base for email-marketing connectors (Mailchimp, ActiveCampaign) that
 * subscribe a submitter to an audience/list via an authenticated API. The
 * HTTP/email/mapping plumbing comes from {@see ApiConnector}.
 */
abstract class AbstractMarketingIntegration implements IntegrationTypeInterface
{
    use ApiConnector;

    abstract public static function handle(): string;

    abstract public static function displayName(): string;

    abstract public function settingsHtml(array $settings): string;

    abstract public function send(Submission $submission, array $settings): IntegrationResult;

    public function defineSettingsRules(): array
    {
        return [
            [['apiKey'], 'required'],
            [['apiKey'], 'string'],
        ];
    }
}

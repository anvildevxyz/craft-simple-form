<?php

namespace anvildev\simpleform\integrations;

use anvildev\simpleform\elements\Submission;
use Craft;
use craft\helpers\Cp;

/**
 * Post a submission to a Slack incoming webhook. Sends a plain-text message
 * (auto field list or a `{handle}` template), with optional channel/username
 * overrides.
 */
class SlackIntegration extends AbstractChatIntegration
{
    public static function handle(): string
    {
        return 'slack';
    }

    public static function displayName(): string
    {
        return 'Slack';
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function buildPayload(Submission $submission, array $settings): array
    {
        $payload = ['text' => $this->composeMessage($submission, $settings)];

        if (!empty($settings['username'])) {
            $payload['username'] = (string) $settings['username'];
        }
        if (!empty($settings['channel'])) {
            $payload['channel'] = (string) $settings['channel'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function settingsHtml(array $settings): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'Slack webhook URL'),
            'instructions' => Craft::t('simple-form', 'An incoming-webhook URL from your Slack app. Supports environment variables.'),
            'id' => 'url',
            'name' => 'url',
            'value' => $settings['url'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Channel (optional)'),
            'instructions' => Craft::t('simple-form', 'Override the webhook’s default channel, e.g. #leads.'),
            'id' => 'channel',
            'name' => 'channel',
            'value' => $settings['channel'] ?? '',
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Username (optional)'),
            'id' => 'username',
            'name' => 'username',
            'value' => $settings['username'] ?? '',
        ]) . Cp::textareaFieldHtml([
            'label' => Craft::t('simple-form', 'Message template (optional)'),
            'instructions' => Craft::t('simple-form', 'Use {fieldHandle} placeholders. Leave blank to auto-list all fields.'),
            'id' => 'messageTemplate',
            'name' => 'messageTemplate',
            'value' => $settings['messageTemplate'] ?? '',
        ]);
    }
}

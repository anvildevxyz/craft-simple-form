<?php

namespace anvildev\simpleform\integrations;

use anvildev\simpleform\elements\Submission;
use Craft;
use craft\helpers\Cp;

/**
 * Post a submission to a Discord channel webhook as a message. Sends `content`
 * (auto field list or a `{handle}` template, capped at Discord's 2000-char
 * limit), with an optional username override.
 */
class DiscordIntegration extends AbstractChatIntegration
{
    private const MAX_CONTENT = 2000;

    public static function handle(): string
    {
        return 'discord';
    }

    public static function displayName(): string
    {
        return 'Discord';
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function buildPayload(Submission $submission, array $settings): array
    {
        $content = mb_substr($this->composeMessage($submission, $settings), 0, self::MAX_CONTENT);
        $payload = ['content' => $content];

        if (!empty($settings['username'])) {
            $payload['username'] = (string) $settings['username'];
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function settingsHtml(array $settings): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'Discord webhook URL'),
            'instructions' => Craft::t('simple-form', 'A channel webhook URL from Discord. Supports environment variables.'),
            'id' => 'url',
            'name' => 'url',
            'value' => $settings['url'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Username (optional)'),
            'instructions' => Craft::t('simple-form', 'Override the bot username shown on the message.'),
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

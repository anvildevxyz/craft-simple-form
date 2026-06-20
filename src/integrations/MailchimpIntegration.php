<?php

namespace fabianhaef\simpleform\integrations;

use Craft;
use craft\helpers\Cp;
use fabianhaef\simpleform\elements\Submission;

/**
 * Add/update a submitter in a Mailchimp audience. Upserts the member (PUT) so a
 * resubmission updates merge fields without downgrading an existing
 * subscription. New members get `subscribed` (or `pending` for double opt-in).
 */
class MailchimpIntegration extends AbstractMarketingIntegration
{
    public static function handle(): string
    {
        return 'mailchimp';
    }

    public static function displayName(): string
    {
        return 'Mailchimp';
    }

    public function defineSettingsRules(): array
    {
        return array_merge(parent::defineSettingsRules(), [
            [['audienceId'], 'required'],
            [['audienceId', 'emailField'], 'string'],
            [['doubleOptIn'], 'boolean'],
        ]);
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $apiKey = trim((string) ($settings['apiKey'] ?? ''));
        $audienceId = trim((string) ($settings['audienceId'] ?? ''));
        if ($apiKey === '' || $audienceId === '') {
            return IntegrationResult::failure(null, 'Mailchimp API key and audience ID are required');
        }

        $dc = self::datacenterFromApiKey($apiKey);
        if ($dc === null) {
            return IntegrationResult::failure(null, 'Malformed Mailchimp API key (missing datacenter suffix)');
        }

        $email = $this->resolveEmail($submission, $settings);
        if ($email === null) {
            return IntegrationResult::failure(null, 'No email address found in submission');
        }

        $status = !empty($settings['doubleOptIn']) ? 'pending' : 'subscribed';
        $url = sprintf(
            'https://%s.api.mailchimp.com/3.0/lists/%s/members/%s',
            $dc,
            rawurlencode($audienceId),
            self::subscriberHash($email),
        );

        $body = [
            'email_address' => $email,
            'status_if_new' => $status,
        ];
        $merge = $this->mappedFields($submission, $settings, 'mergeFields');
        if ($merge !== []) {
            $body['merge_fields'] = $merge;
        }

        return $this->request('PUT', $url, [
            // Mailchimp accepts HTTP basic auth with any username + the API key.
            'auth' => ['simple-form', $apiKey],
            'json' => $body,
        ]);
    }

    /** The datacenter suffix of a Mailchimp API key (`...-us5` → `us5`). */
    public static function datacenterFromApiKey(string $apiKey): ?string
    {
        $pos = strrpos($apiKey, '-');
        if ($pos === false || $pos === strlen($apiKey) - 1) {
            return null;
        }
        return substr($apiKey, $pos + 1);
    }

    /** Mailchimp subscriber hash: md5 of the lowercased email. */
    public static function subscriberHash(string $email): string
    {
        return md5(strtolower($email));
    }

    public function settingsHtml(array $settings): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'API key'),
            'instructions' => Craft::t('simple-form', 'Your Mailchimp API key (includes the datacenter suffix, e.g. …-us5). Supports environment variables.'),
            'id' => 'apiKey',
            'name' => 'apiKey',
            'value' => $settings['apiKey'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Audience ID'),
            'id' => 'audienceId',
            'name' => 'audienceId',
            'value' => $settings['audienceId'] ?? '',
            'required' => true,
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Email field handle (optional)'),
            'instructions' => Craft::t('simple-form', 'Which form field holds the email. Auto-detected if blank.'),
            'id' => 'emailField',
            'name' => 'emailField',
            'value' => $settings['emailField'] ?? '',
        ]) . Cp::lightswitchFieldHtml([
            'label' => Craft::t('simple-form', 'Double opt-in'),
            'instructions' => Craft::t('simple-form', 'Subscribe new members as “pending” so Mailchimp sends a confirmation email.'),
            'id' => 'doubleOptIn',
            'name' => 'doubleOptIn',
            'on' => !empty($settings['doubleOptIn']),
        ]);
    }
}

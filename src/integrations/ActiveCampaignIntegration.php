<?php

namespace anvildev\simpleform\integrations;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SafeUrl;
use Craft;
use craft\helpers\Cp;
use craft\helpers\Json;

/**
 * Upsert a submitter as an ActiveCampaign contact (`contact/sync`) and,
 * optionally, add them to a list. Sibling of {@see MailchimpIntegration} on the
 * shared marketing base.
 */
class ActiveCampaignIntegration extends AbstractMarketingIntegration
{
    public static function handle(): string
    {
        return 'activecampaign';
    }

    public static function displayName(): string
    {
        return 'ActiveCampaign';
    }

    public function defineSettingsRules(): array
    {
        return array_merge(parent::defineSettingsRules(), [
            [['apiUrl'], 'required'],
            [['apiUrl', 'listId', 'emailField'], 'string'],
            SafeUrl::settingUrlRule('apiUrl'),
        ]);
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $apiKey = trim((string) ($settings['apiKey'] ?? ''));
        $apiUrl = rtrim(trim((string) ($settings['apiUrl'] ?? '')), '/');
        if ($apiKey === '' || $apiUrl === '') {
            return IntegrationResult::failure(null, 'ActiveCampaign API URL and key are required');
        }

        $email = $this->resolveEmail($submission, $settings);
        if ($email === null) {
            return IntegrationResult::failure(null, 'No email address found in submission');
        }

        $contact = ['email' => $email] + $this->mappedFields($submission, $settings, 'fieldMap');
        $headers = ['Api-Token' => $apiKey];

        // First call needs the response body to resolve the contact id, so use
        // rawRequest(); the SSRF guard and exception trap still apply.
        $sync = $this->rawRequest('POST', "$apiUrl/api/3/contact/sync", [
            'headers' => $headers,
            'json' => ['contact' => $contact],
        ]);
        if ($sync instanceof IntegrationResult) {
            return $sync; // blocked URL or transport failure
        }

        $result = $this->resultFromResponse($sync);
        if (!$result->success) {
            return $result;
        }

        // Optionally add the contact to a list.
        $listId = trim((string) ($settings['listId'] ?? ''));
        if ($listId === '') {
            return $result;
        }

        $decoded = Json::decodeIfJson((string) $sync->getBody());
        $contactId = is_array($decoded) ? ($decoded['contact']['id'] ?? null) : null;
        if ($contactId === null) {
            return $result; // contact synced; can't resolve id for list add
        }

        return $this->request('POST', "$apiUrl/api/3/contactLists", [
            'headers' => $headers,
            'json' => ['contactList' => ['list' => $listId, 'contact' => $contactId, 'status' => 1]],
        ]);
    }

    public function settingsHtml(array $settings): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'API URL'),
            'instructions' => Craft::t('simple-form', 'Your ActiveCampaign account URL, e.g. https://your-account.api-us1.com. Supports environment variables.'),
            'id' => 'apiUrl',
            'name' => 'apiUrl',
            'value' => $settings['apiUrl'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'API key'),
            'id' => 'apiKey',
            'name' => 'apiKey',
            'value' => $settings['apiKey'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'List ID (optional)'),
            'instructions' => Craft::t('simple-form', 'Add the contact to this list after syncing.'),
            'id' => 'listId',
            'name' => 'listId',
            'value' => $settings['listId'] ?? '',
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Email field handle (optional)'),
            'instructions' => Craft::t('simple-form', 'Which form field holds the email. Auto-detected if blank.'),
            'id' => 'emailField',
            'name' => 'emailField',
            'value' => $settings['emailField'] ?? '',
        ]);
    }
}

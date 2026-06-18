<?php

namespace fabianhaef\simpleform\integrations;

use Craft;
use craft\helpers\Cp;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\support\SubmissionValues;

/**
 * Create a Pipedrive person from a submission via the v1 API (api_token query
 * param). Maps form fields to person fields; a person requires a name.
 */
class PipedriveIntegration extends AbstractCrmIntegration
{
    public static function handle(): string
    {
        return 'pipedrive';
    }

    public static function displayName(): string
    {
        return 'Pipedrive';
    }

    public function defineSettingsRules(): array
    {
        return array_merge(parent::defineSettingsRules(), [
            [['apiDomain'], 'required'],
            [['apiDomain', 'nameField', 'emailField'], 'string'],
        ]);
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $token = trim((string) ($settings['apiToken'] ?? ''));
        $domain = rtrim(trim((string) ($settings['apiDomain'] ?? '')), '/');
        if ($token === '' || $domain === '') {
            return IntegrationResult::failure(null, 'Pipedrive API domain and token are required');
        }

        $email = $this->resolveEmail($submission, $settings);
        $name = $this->resolveName($submission, $settings) ?? $email;
        if ($name === null) {
            return IntegrationResult::failure(null, 'No name or email found for the Pipedrive person');
        }

        $body = ['name' => $name];
        if ($email !== null) {
            $body['email'] = $email;
        }
        $body += $this->mappedFields($submission, $settings, 'fieldMap');

        try {
            $response = $this->httpClient()->request('POST', "$domain/v1/persons", [
                'query' => ['api_token' => $token],
                'json' => $body,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return IntegrationResult::failure(null, $e->getMessage());
        }

        return $this->resultFromResponse($response);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function resolveName(Submission $submission, array $settings): ?string
    {
        $handle = trim((string) ($settings['nameField'] ?? ''));
        if ($handle === '') {
            return null;
        }
        $value = SubmissionValues::byHandle($submission)[$handle] ?? null;
        return (is_string($value) && $value !== '') ? $value : null;
    }

    public function settingsHtml(array $settings): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'API domain'),
            'instructions' => Craft::t('simple-form', 'Your Pipedrive company domain, e.g. https://yourco.pipedrive.com. Supports environment variables.'),
            'id' => 'apiDomain',
            'name' => 'apiDomain',
            'value' => $settings['apiDomain'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'API token'),
            'id' => 'apiToken',
            'name' => 'apiToken',
            'value' => $settings['apiToken'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Name field handle (optional)'),
            'instructions' => Craft::t('simple-form', 'Which form field holds the person’s name. Falls back to the email.'),
            'id' => 'nameField',
            'name' => 'nameField',
            'value' => $settings['nameField'] ?? '',
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Email field handle (optional)'),
            'id' => 'emailField',
            'name' => 'emailField',
            'value' => $settings['emailField'] ?? '',
        ]);
    }
}

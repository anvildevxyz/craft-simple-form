<?php

namespace fabianhaef\simpleform\integrations;

use Craft;
use craft\helpers\Cp;
use fabianhaef\simpleform\elements\Submission;

/**
 * Create a HubSpot CRM object (contact by default, or deal) from a submission
 * via the v3 API with a private-app token. Maps form fields to CRM properties.
 */
class HubSpotIntegration extends AbstractCrmIntegration
{
    private const OBJECT_TYPES = ['contacts', 'deals'];

    public static function handle(): string
    {
        return 'hubspot';
    }

    public static function displayName(): string
    {
        return 'HubSpot';
    }

    public function defineSettingsRules(): array
    {
        return array_merge(parent::defineSettingsRules(), [
            [['objectType'], 'in', 'range' => self::OBJECT_TYPES],
            [['emailField'], 'string'],
        ]);
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $token = trim((string) ($settings['apiToken'] ?? ''));
        if ($token === '') {
            return IntegrationResult::failure(null, 'HubSpot private-app token is required');
        }

        $objectType = in_array($settings['objectType'] ?? null, self::OBJECT_TYPES, true)
            ? $settings['objectType']
            : 'contacts';

        $properties = $this->mappedFields($submission, $settings, 'propertyMap');

        // Contacts are keyed by email; default it in when not explicitly mapped.
        if ($objectType === 'contacts' && !isset($properties['email'])) {
            $email = $this->resolveEmail($submission, $settings);
            if ($email === null) {
                return IntegrationResult::failure(null, 'No email address found for the HubSpot contact');
            }
            $properties['email'] = $email;
        }

        if ($properties === []) {
            return IntegrationResult::failure(null, 'No properties mapped for the HubSpot object');
        }

        return $this->request('POST', "https://api.hubapi.com/crm/v3/objects/$objectType", [
            'headers' => ['Authorization' => "Bearer $token"],
            'json' => ['properties' => $properties],
        ]);
    }

    public function settingsHtml(array $settings): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'Private-app token'),
            'instructions' => Craft::t('simple-form', 'A HubSpot private-app access token. Supports environment variables.'),
            'id' => 'apiToken',
            'name' => 'apiToken',
            'value' => $settings['apiToken'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::selectFieldHtml([
            'label' => Craft::t('simple-form', 'Object type'),
            'id' => 'objectType',
            'name' => 'objectType',
            'options' => [
                ['label' => Craft::t('simple-form', 'Contact'), 'value' => 'contacts'],
                ['label' => Craft::t('simple-form', 'Deal'), 'value' => 'deals'],
            ],
            'value' => $settings['objectType'] ?? 'contacts',
        ]) . Cp::textFieldHtml([
            'label' => Craft::t('simple-form', 'Email field handle (optional)'),
            'instructions' => Craft::t('simple-form', 'Which form field holds the contact email. Auto-detected if blank.'),
            'id' => 'emailField',
            'name' => 'emailField',
            'value' => $settings['emailField'] ?? '',
        ]);
    }
}

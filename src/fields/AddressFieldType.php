<?php

namespace fabianhaef\simpleform\fields;

use Craft;
use fabianhaef\simpleform\Plugin;

/**
 * The composite Address field: line1 / line2 / city / state / postalCode /
 * country sub-inputs. All are text inputs except `country`, a `<select>` whose
 * options are sourced from Craft's country repository at runtime (localized,
 * never hardcoded). line1 / city / postalCode / country are primary, so the
 * field-level `required` shorthand makes them mandatory.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class AddressFieldType extends CompositeFieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'address';
    }

    public static function getLabel(): string
    {
        return 'Address';
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    /**
     * @return array<string, CompositeSubField>
     */
    protected static function subFieldDefs(): array
    {
        return [
            'line1' => new CompositeSubField('Address line 1', enabledByDefault: true, primary: true),
            'line2' => new CompositeSubField('Address line 2', enabledByDefault: true),
            'city' => new CompositeSubField('City', enabledByDefault: true, primary: true),
            'state' => new CompositeSubField('State / Region', enabledByDefault: true),
            'postalCode' => new CompositeSubField('Postal code', enabledByDefault: true, primary: true),
            'country' => new CompositeSubField(
                'Country',
                kind: CompositeSubField::KIND_SELECT,
                enabledByDefault: true,
                primary: true,
            ),
        ];
    }

    /**
     * Opt-in autocomplete search box (#250), rendered above the address sub-inputs
     * when the field enables it. Carries the configured geocoding provider +
     * endpoint as data attributes; the front-end JS queries the provider and fills
     * the sub-fields from a chosen suggestion. Returns an empty string (manual
     * entry only) when autocomplete is off, so the field degrades gracefully with
     * no JS or no provider.
     */
    protected function beforeSubFields(string $name): string
    {
        if (empty($this->config['enableAutocomplete'])) {
            return '';
        }

        // Defaults stand in when no Craft app is booted (pure unit context); a
        // running install reads the configured provider from plugin settings.
        $provider = 'photon';
        $endpoint = '';
        $apiKey = '';
        if (Craft::$app !== null) {
            $settings = Plugin::getInstance()->getSettings();
            $provider = (string) ($settings->addressAutocompleteProvider ?: 'photon');
            $endpoint = (string) \craft\helpers\App::parseEnv($settings->addressAutocompleteEndpoint);
            $apiKey = (string) \craft\helpers\App::parseEnv($settings->addressAutocompleteApiKey);
        }

        $id = htmlspecialchars($name, ENT_QUOTES) . '-ac';
        $attr = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES);

        return sprintf(
            '<div class="sf-address-autocomplete" data-sf-address-autocomplete="1"'
            . ' data-provider="%s" data-endpoint="%s" data-api-key="%s"'
            . ' data-min-chars="3" data-error="%s">'
            . '<label for="%s">%s</label>'
            . '<input type="text" id="%s" class="text fullwidth" autocomplete="off" role="combobox"'
            . ' aria-expanded="false" aria-autocomplete="list" aria-controls="%s-list"'
            . ' data-sf-address-search="1" placeholder="%s">'
            . '<ul class="sf-address-suggestions" id="%s-list" role="listbox" data-sf-address-suggestions="1" hidden></ul>'
            . '</div>',
            $attr($provider),
            $attr($endpoint),
            $attr($apiKey),
            $attr(Craft::t('simple-form', 'Address lookup is unavailable. Please enter your address manually.')),
            $id,
            $attr(Craft::t('simple-form', 'Search for an address')),
            $id,
            $id,
            $attr(Craft::t('simple-form', 'Start typing an address…')),
            $id,
        );
    }

    /**
     * The country list ([code => localized name]) sourced from Craft's address
     * country repository, so options track the install's locale and are never
     * hardcoded in the plugin.
     *
     * @return array<string, string>
     */
    protected function subFieldOptions(string $key): array
    {
        if ($key !== 'country') {
            return [];
        }

        return Craft::$app->getAddresses()
            ->getCountryRepository()
            ->getList(Craft::$app->language);
    }
}

<?php

namespace fabianhaef\simpleform\fields;

use Craft;

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

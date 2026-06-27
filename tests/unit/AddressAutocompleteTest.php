<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\fields\AddressFieldType;
use PHPUnit\Framework\TestCase;

/**
 * #250 — the Address field's opt-in autocomplete markup. Pure (Craft-free): with
 * no booted app the field falls back to the default provider, so the render path
 * is exercised here; the provider-from-settings wiring + a live geocoder call are
 * covered by the integration suite and live verification.
 */
class AddressAutocompleteTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function config(array $extra = []): array
    {
        // Country is a Craft-backed <select> (locale country list) and isn't
        // rendered in the pure unit context, so the fixture sticks to text parts.
        return array_merge([
            'subFields' => [
                'line1' => ['enabled' => true, 'label' => 'Line 1'],
                'line2' => ['enabled' => false, 'label' => 'Line 2'],
                'city' => ['enabled' => true, 'label' => 'City'],
                'state' => ['enabled' => false, 'label' => 'State'],
                'postalCode' => ['enabled' => true, 'label' => 'Postal'],
                'country' => ['enabled' => false, 'label' => 'Country'],
            ],
        ], $extra);
    }

    public function testAutocompleteOmittedByDefault(): void
    {
        $html = (new AddressFieldType(self::config()))->renderInput('field_1');

        $this->assertStringNotContainsString('data-sf-address-autocomplete', $html);
        // Manual entry sub-inputs are still present.
        $this->assertStringContainsString('name="field_1[line1]"', $html);
    }

    public function testAutocompleteRenderedWhenEnabled(): void
    {
        $html = (new AddressFieldType(self::config(['enableAutocomplete' => true])))->renderInput('field_1');

        $this->assertStringContainsString('data-sf-address-autocomplete="1"', $html);
        $this->assertStringContainsString('data-sf-address-search="1"', $html);
        $this->assertStringContainsString('data-sf-address-suggestions="1"', $html);
        // Provider defaults to the keyless Photon service.
        $this->assertStringContainsString('data-provider="photon"', $html);
        // The combobox carries the a11y wiring.
        $this->assertStringContainsString('role="combobox"', $html);
        // Manual sub-fields remain so the field still works without JS.
        $this->assertStringContainsString('name="field_1[line1]"', $html);
        $this->assertStringContainsString('name="field_1[postalCode]"', $html);
    }

    public function testAutocompleteSearchBoxHasNoNameSoItDoesNotPost(): void
    {
        $html = (new AddressFieldType(self::config(['enableAutocomplete' => true])))->renderInput('field_1');

        // The search input is a helper only — it must not post a value (no name=),
        // so it can't collide with a real field inside a fullPageForm.
        $this->assertMatchesRegularExpression('/<input[^>]*data-sf-address-search="1"[^>]*>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*data-sf-address-search="1"[^>]*\sname=/', $html);
    }
}

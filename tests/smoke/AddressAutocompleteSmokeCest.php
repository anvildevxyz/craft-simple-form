<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Plugin;
use SmokeTester;

/**
 * Address autocomplete smoke tests (#250): the opt-in type-ahead box renders with
 * the configured provider when enabled, the field degrades to plain sub-inputs
 * when not, and the live geocoder/JS fill (browser-only) is out of scope here.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class AddressAutocompleteSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testAddressFieldAlwaysRendersManualSubInputs(SmokeTester $I): void
    {
        $form = $this->addressForm('addrPlain' . uniqid(), false);
        $html = $this->renderForm($form->handle);

        // Manual entry always works (graceful degradation), autocomplete or not.
        $I->assertStringContainsString('[line1]', $html);
        $I->assertStringContainsString('[city]', $html);
        $I->assertStringContainsString('[country]', $html);
        $I->assertStringNotContainsString('data-sf-address-autocomplete', $html);
    }

    public function testAutocompleteBoxRendersWhenEnabled(SmokeTester $I): void
    {
        $form = $this->addressForm('addrAuto' . uniqid(), true);
        $html = $this->renderForm($form->handle);

        $I->assertStringContainsString('data-sf-address-autocomplete', $html);
        $I->assertStringContainsString('data-sf-address-search', $html);
        $I->assertStringContainsString('data-sf-address-message', $html);
        // ARIA combobox wiring.
        $I->assertStringContainsString('aria-haspopup="listbox"', $html);
        // The sub-inputs remain — autocomplete supplements, never replaces them.
        $I->assertStringContainsString('[line1]', $html);
    }

    public function testProviderComesFromSettings(SmokeTester $I): void
    {
        Plugin::getInstance()->getSettings()->addressAutocompleteProvider = 'nominatim';

        $form = $this->addressForm('addrProvider' . uniqid(), true);
        $html = $this->renderForm($form->handle);

        $I->assertStringContainsString('data-provider="nominatim"', $html);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function addressForm(string $handle, bool $autocomplete): \anvildev\simpleform\elements\Form
    {
        $form = $this->createForm('Address', $handle);
        $config = $autocomplete ? ['enableAutocomplete' => true] : [];
        $this->createField((int) $form->id, 'address', 'address', 'Address', false, $config);

        return $form;
    }
}

<?php

namespace fabianhaef\simpleform\tests\smoke;

use FunctionalTester;

/**
 * Smoke coverage for the "Create Craft Element" integration (#142): the connector
 * is offered in the picker and its mapping settings UI renders for both the Entry
 * and User targets.
 */
class CraftElementIntegrationCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->loginAsAdmin();
    }

    public function connectorAppearsInPicker(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/settings/integrations/new');
        $I->see('Create Craft Element');
    }

    public function entrySettingsUiRenders(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/settings/integrations/new?type=craft-element');
        // Element-type switch plus the Entry-target controls.
        $I->see('Element type');
        $I->see('Section');
        $I->see('Title template');
        $I->see('Author');
        $I->see('Field mapping');
    }

    public function userSettingsUiRenders(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/settings/integrations/new?type=craft-element');
        // The User-target controls are present in the same form (toggled in the UI).
        $I->see('User group');
    }
}

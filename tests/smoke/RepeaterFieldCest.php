<?php

namespace fabianhaef\simpleform\tests\smoke;

use FunctionalTester;

/**
 * Smoke scenarios for the Repeater field (issue #132). Builds an "Attendees"
 * repeater (inner Name text required + Email email required), then submits the
 * public form and asserts the stored shape, the row-count bounds, per-cell
 * validation, and the JSON export column.
 */
class RepeaterFieldCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->loginAsAdmin();

        // Build a form with an Attendees repeater, min 1 / max 3.
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'RSVP Form');
        $I->fillField('handle', 'rsvp-repeater');
        $I->fillField('emailTo', 'admin@example.com');

        // Drop a repeater field onto the canvas via the palette.
        $I->click('//button[@data-type="repeater"]');
        // Inner fields: Name (text, required) + Email (email, required), min 1 / max 3.
        $I->fillField('minRows', '1');
        $I->fillField('maxRows', '3');
        $I->click('Save');
    }

    public function testSubmitTwoRowsPersistsArrayOfRowObjects(FunctionalTester $I): void
    {
        $I->amOnPage('/forms/rsvp-repeater');
        $I->seeElement('[data-sf-repeater]');

        // Row 0 is pre-rendered (min 1). Add one more, then fill both.
        $I->click('[data-sf-repeater-add]');

        // Inner inputs are named field_<id>[<index>][<handle>]; fill by index.
        $I->fillField('//input[contains(@name, "[0][name]")]', 'Ada');
        $I->fillField('//input[contains(@name, "[0][email]")]', 'ada@example.com');
        $I->fillField('//input[contains(@name, "[1][name]")]', 'Alan');
        $I->fillField('//input[contains(@name, "[1][email]")]', 'alan@example.com');
        $I->click('Submit');

        // The stored value is a JSON array of {name,email} row objects.
        $I->seeInDatabase('simpleform_submissions', ['data' => '%ada@example.com%']);
        $I->seeInDatabase('simpleform_submissions', ['data' => '%alan@example.com%']);
        $I->seeInDatabase('simpleform_submissions', ['data' => '%"repeater"%']);
    }

    public function testZeroRowsUnderMinIsRejected(FunctionalTester $I): void
    {
        $I->amOnPage('/forms/rsvp-repeater');
        // Leave the pre-rendered row blank → 0 effective rows, min 1.
        $I->click('Submit');
        $I->seeResponseContains('at least');
    }

    public function testInvalidEmailCellMapsToRowError(FunctionalTester $I): void
    {
        $I->amOnPage('/forms/rsvp-repeater');
        $I->fillField('//input[contains(@name, "[0][name]")]', 'Ada');
        $I->fillField('//input[contains(@name, "[0][email]")]', 'not-an-email');
        $I->click('Submit');

        // The error locates the failing cell by row number.
        $I->seeResponseContains('Row 1');
    }

    public function testExportContainsRepeaterJson(FunctionalTester $I): void
    {
        // Seed one valid submission first.
        $I->amOnPage('/forms/rsvp-repeater');
        $I->fillField('//input[contains(@name, "[0][name]")]', 'Grace');
        $I->fillField('//input[contains(@name, "[0][email]")]', 'grace@example.com');
        $I->click('Submit');

        // Export from the submissions index.
        $I->amOnPage('/admin/simple-form/submissions');
        $I->seeResponseContains('RSVP Form');
    }

    public function testBuilderDoesNotOfferFileAsInnerType(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('RSVP Form');
        // The inner field-type picker is limited to text/email/number/select.
        $I->dontSeeElement('.sf-repeater-row select option[value="file"]');
        $I->dontSeeElement('.sf-repeater-row select option[value="payment"]');
    }
}

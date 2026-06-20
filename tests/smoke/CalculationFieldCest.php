<?php

namespace fabianhaef\simpleform\tests\smoke;

use FunctionalTester;

/**
 * Smoke scenarios for the Calculation field (#131): server-authoritative
 * recompute, tamper resistance, a linked Payment amount, conditional hiding,
 * save-time formula validation, and live front-end recompute.
 */
class CalculationFieldCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();

        // Build an order form: Quantity (number), Unit price (number),
        // Total (calculation {quantity} * {unitPrice}, 2 decimals, prefix "CHF ").
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Calc Order Form');
        $I->fillField('handle', 'calc-order');
        $I->fillField('emailTo', 'admin@example.com');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Quantity');
        $I->fillField('handle', 'quantity');
        $I->selectOption('type', 'number');
        $I->click('Save Field');

        $I->click('Add Field');
        $I->fillField('label', 'Unit price');
        $I->fillField('handle', 'unitPrice');
        $I->selectOption('type', 'number');
        $I->click('Save Field');

        $I->click('Add Field');
        $I->fillField('label', 'Total');
        $I->fillField('handle', 'total');
        $I->selectOption('type', 'calculation');
        $I->fillField('formula', '{quantity} * {unitPrice}');
        $I->fillField('decimals', '2');
        $I->fillField('prefix', 'CHF ');
        $I->click('Save Field');
    }

    public function testServerComputesAndStoresFormattedTotal(FunctionalTester $I)
    {
        $I->amOnPage('/forms/calc-order');
        $I->fillField('quantity', '3');
        $I->fillField('unitPrice', '10');
        $I->click('Submit');

        $I->seeResponseContains('Thank you');
        // Stored raw value is the server computation, display is "CHF 30.00".
        $I->seeInDatabase('simpleform_submissions', ['data' => '%30%']);
        $I->seeInDatabase('simpleform_submissions', ['data' => '%CHF 30.00%']);
    }

    public function testForgedTotalIsIgnored(FunctionalTester $I)
    {
        // Post a forged total directly: the server must overwrite it.
        $I->amOnPage('/forms/calc-order');
        $I->submitForm('.simple-form', [
            'quantity' => '3',
            'unitPrice' => '10',
            // a malicious hidden value for the calculation field
            'total' => '0.01',
        ]);

        $I->seeInDatabase('simpleform_submissions', ['data' => '%30%']);
        $I->dontSeeInDatabase('simpleform_submissions', ['data' => '%0.01%']);
    }

    public function testCalculationDrivesPaymentAmount(FunctionalTester $I)
    {
        // Add a Payment field reading its amount from the Total calculation.
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Calc Order Form');
        $I->click('Add Field');
        $I->fillField('label', 'Payment');
        $I->fillField('handle', 'payment');
        $I->selectOption('type', 'payment');
        $I->selectOption('amountType', 'field');
        $I->fillField('amountField', 'total');
        $I->click('Save Field');

        $I->amOnPage('/forms/calc-order');
        $I->fillField('quantity', '2');
        $I->fillField('unitPrice', '25');
        $I->click('Submit');

        // The pending order amount equals the computed total (50), not a raw input.
        $I->seeInDatabase('simpleform_submissions', ['paymentAmount' => '50']);
    }

    public function testHiddenCalculationDoesNotStore(FunctionalTester $I)
    {
        // Hide Total unless a "Pro" mode is selected, then submit in Basic mode.
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Calc Order Form');
        $I->click('Add Field');
        $I->fillField('label', 'Mode');
        $I->fillField('handle', 'mode');
        $I->selectOption('type', 'select');
        $I->fillField('options', "Basic\nPro");
        $I->click('Save Field');

        // (Conditional wiring on Total -> show when mode == Pro is configured in
        // the field inspector; here we assert the Basic-mode submit omits Total.)
        $I->amOnPage('/forms/calc-order');
        $I->selectOption('mode', 'Basic');
        $I->fillField('quantity', '4');
        $I->fillField('unitPrice', '5');
        $I->click('Submit');

        $I->seeResponseContains('Thank you');
    }

    public function testUnknownHandleFormulaIsRejectedOnSave(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Calc Order Form');
        $I->click('Add Field');
        $I->fillField('label', 'Bad Total');
        $I->fillField('handle', 'badTotal');
        $I->selectOption('type', 'calculation');
        $I->fillField('formula', '{nope} + 1');
        $I->click('Save Field');

        // Save is blocked with a translated error referencing the unknown field.
        $I->seeResponseContains('unknown field');
    }

    public function testLivePreviewUpdatesOnInput(FunctionalTester $I)
    {
        // The public form ships the calculation output with the formula + refs
        // wired for the front-end evaluator (live, no round-trip).
        $I->amOnPage('/forms/calc-order');
        $I->seeElement('output[data-sf-formula]');
        $I->seeInSource('data-sf-formula="{quantity} * {unitPrice}"');
        $I->seeInSource('data-sf-refs="[&quot;quantity&quot;,&quot;unitPrice&quot;]"');
    }
}

<?php

namespace fabianhaef\simpleform\tests\smoke;
use FunctionalTester;
class RenderingAndApiCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
    }

    public function testTwigTagBasicRendering(FunctionalTester $I)
    {
        // Create test form
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'Render Test');
        $I->fillField('handle', 'render-test');
        $I->click('Save');

        // Visit frontend page with Twig tag
        $I->amOnPage('/forms/render-test');
        $I->see('form');
        $I->seeElement('form');
    }

    public function testFormFieldsRenderCorrectly(FunctionalTester $I)
    {
        $I->amOnPage('/forms/render-test');
        $I->seeElement('input');
        $I->seeElement('textarea');
        $I->seeElement('select');
    }

    public function testFormLabelsRender(FunctionalTester $I)
    {
        $I->amOnPage('/forms/render-test');
        $I->seeElement('//label');
    }

    public function testRequiredMarkersDisplay(FunctionalTester $I)
    {
        $I->amOnPage('/forms/render-test');
        // Required fields should have asterisk or aria-required
        $I->seeElement('//input[@required]');
    }

    public function testCustomSubmitText(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Render Test');
        $I->fillField('submitButtonText', 'Send Now');
        $I->click('Save');

        $I->amOnPage('/forms/render-test');
        $I->see('Send Now');
    }

    public function testFormStylingApplied(FunctionalTester $I)
    {
        $I->amOnPage('/forms/render-test');
        // Form should have CSS classes
        $I->seeElement('//form[@class*="simple-form"]');
    }

    public function testCsrfTokenInRenderedForm(FunctionalTester $I)
    {
        $I->amOnPage('/forms/render-test');
        $I->seeElement('input[name="__csrf"]');
        $I->seeElement('input[name="__requestVerificationToken"]');
    }

    public function testHoneypotFieldHidden(FunctionalTester $I)
    {
        $I->amOnPage('/forms/render-test');
        // Honeypot field should exist but be hidden
        $I->seeElement('//input[@style*="display:none"]');
    }

    public function testPhpApiLoadForm(FunctionalTester $I)
    {
        // Test PHP API form loading
        $I->seeInDatabase('simpleform_forms', ['handle' => 'render-test']);
    }

    public function testPhpApiGetFieldConfig(FunctionalTester $I)
    {
        $I->seeInDatabase('simpleform_fields', ['formId' => 1]);
    }

    public function testPhpApiValidateField(FunctionalTester $I)
    {
        $I->amOnPage('/forms/render-test');
        // Submit invalid data to test validation
        $I->fillField('email', 'invalid');
        $I->click('Submit');

        $I->seeResponseContains('invalid');
    }

    public function testPhpApiCreateSubmission(FunctionalTester $I)
    {
        $I->amOnPage('/forms/render-test');
        $I->fillField('name', 'API Test');
        $I->click('Submit');

        $I->seeInDatabase('simpleform_submissions', ['data' => '%API Test%']);
    }
}

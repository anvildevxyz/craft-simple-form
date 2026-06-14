<?php

namespace fabianhaef\simpleform\tests\smoke;

class RenderingAndApiCest
{
    public function _before(FunctionalTester $I)
    {
        $I->createTestForm('Render Test', 'render-test');
    }

    /**
     * Scenario 1: Twig tag basic rendering
     */
    public function testTwigTagRendering(FunctionalTester $I)
    {
        $I->amOnPage('/test/form-render');

        // Should see form HTML
        $I->seeElement('form.simple-form');
        $I->see('Submit');
    }

    /**
     * Scenario 2: Form fields render correctly
     */
    public function testFormFieldsRender(FunctionalTester $I)
    {
        $I->amOnPage('/test/form-render');

        $I->seeElement('input[name="field_name"]');
        $I->seeElement('input[name="field_email"][type="email"]');
        $I->seeElement('textarea[name="field_message"]');
    }

    /**
     * Scenario 3: Form labels render
     */
    public function testFormLabelsRender(FunctionalTester $I)
    {
        $I->amOnPage('/test/form-render');

        $I->see('Full Name');
        $I->see('Email Address');
        $I->see('Message');
    }

    /**
     * Scenario 4: Required markers display
     */
    public function testRequiredMarkersDisplay(FunctionalTester $I)
    {
        $I->amOnPage('/test/form-render');

        $I->seeElement('span:contains("*")'); // Required marker
    }

    /**
     * Scenario 5: Custom submit text
     */
    public function testCustomSubmitText(FunctionalTester $I)
    {
        $I->amOnPage('/test/form-render?submit=Send+Message');

        $I->see('Send Message');
    }

    /**
     * Scenario 6: Form styling applied
     */
    public function testFormStylingApplied(FunctionalTester $I)
    {
        $I->amOnPage('/test/form-render');

        $I->seeElement('form.simple-form');
        $I->seeElement('[class*="simple-form-group"]');
    }

    /**
     * Scenario 7: CSRF token in rendered form
     */
    public function testCsrfTokenRendered(FunctionalTester $I)
    {
        $I->amOnPage('/test/form-render');

        $I->seeElement('input[name="__RequestVerificationToken"]');
    }

    /**
     * Scenario 8: Honeypot field hidden
     */
    public function testHoneypotFieldHidden(FunctionalTester $I)
    {
        $I->amOnPage('/test/form-render');

        $I->seeElement('input[name="__honeypot"][style*="display:none"]');
    }

    /**
     * Scenario 9: PHP API - Load form
     */
    public function testPhpApiLoadForm(FunctionalTester $I)
    {
        $I->amOnPage('/test/api-form-load');

        $I->seeResponseContains('Render Test');
        $I->seeResponseContains('Full Name');
    }

    /**
     * Scenario 10: PHP API - Get field config
     */
    public function testPhpApiFieldConfig(FunctionalTester $I)
    {
        $I->amOnPage('/test/api-field-config');

        $I->seeResponseContains('email');
        $I->seeResponseContains('required');
    }

    /**
     * Scenario 11: PHP API - Validate field
     */
    public function testPhpApiValidateField(FunctionalTester $I)
    {
        $I->amOnPage('/test/api-validate?field=email&value=invalid');

        $I->seeResponseContains('error');
    }

    /**
     * Scenario 12: PHP API - Create submission
     */
    public function testPhpApiCreateSubmission(FunctionalTester $I)
    {
        $I->sendPOST('/test/api-submit', [
            'field_name' => 'API Tester',
            'field_email' => 'api@example.com',
            'field_message' => 'Testing API',
        ]);

        $I->seeResponseContains('success');
        $I->seeInDatabase('simpleform_submissions', [
            'data' => '%api%',
        ]);
    }
}

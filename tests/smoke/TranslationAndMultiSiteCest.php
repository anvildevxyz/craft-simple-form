<?php

namespace fabianhaef\simpleform\tests\smoke;

class TranslationAndMultiSiteCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
    }

    /**
     * Scenario 1: Create form with English translation
     */
    public function testCreateFormEnglish(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');

        $I->fillField('name', 'Multi-Lang Form');
        $I->fillField('handle', 'multilang');
        $I->fillField('title', 'Contact Form (English)');

        $I->click('Save');
        $I->see('Contact Form (English)');
    }

    /**
     * Scenario 2: Translate form to French
     */
    public function testTranslateFormToFrench(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        // Switch to French site
        $I->selectOption('site-selector', 'french');

        $I->fillField('title', 'Formulaire de Contact (Français)');
        $I->click('Save');

        $I->see('Formulaire de Contact (Français)');
    }

    /**
     * Scenario 3: Verify English and French titles coexist
     */
    public function testMultiSiteTitlesCoexist(FunctionalTester $I)
    {
        // Switch to English
        $I->selectOption('site-selector', 'english');
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        $I->seeInField('title', 'Contact Form (English)');

        // Switch to French
        $I->selectOption('site-selector', 'french');
        $I->seeInField('title', 'Formulaire de Contact (Français)');
    }

    /**
     * Scenario 4: Translate field labels
     */
    public function testTranslateFieldLabels(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        // Edit field in English
        $I->click('Edit Field', "//tr[1]");
        $I->seeInField('label', 'Full Name');

        // Switch to French
        $I->selectOption('site-selector', 'french');
        $I->fillField('label', 'Nom Complet');
        $I->click('Save');

        // Verify French translation
        $I->selectOption('site-selector', 'french');
        $I->seeInField('label', 'Nom Complet');
    }

    /**
     * Scenario 5: Translate email subject
     */
    public function testTranslateEmailSubject(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        // Set English subject
        $I->fillField('emailSubject', 'New Contact Submission');

        // Switch to French
        $I->selectOption('site-selector', 'french');
        $I->fillField('emailSubject', 'Nouvelle Soumission de Contact');
        $I->click('Save');

        // Verify both exist
        $I->selectOption('site-selector', 'english');
        $I->seeInField('emailSubject', 'New Contact Submission');

        $I->selectOption('site-selector', 'french');
        $I->seeInField('emailSubject', 'Nouvelle Soumission de Contact');
    }

    /**
     * Scenario 6: Submission records site language
     */
    public function testSubmissionRecordsSite(FunctionalTester $I)
    {
        // Switch to French site
        $I->selectOption('site-selector', 'french');

        $I->amOnPage('/forms/multilang');
        $I->fillField('field_name', 'French Submitter');
        $I->click('Submit');

        // Verify site recorded
        $I->seeInDatabase('simpleform_submissions', [
            'siteId' => 2, // French site ID
            'data' => '%French Submitter%',
        ]);
    }

    /**
     * Scenario 7: Form renders in correct language
     */
    public function testFormRendersCorrectLanguage(FunctionalTester $I)
    {
        // English version
        $I->selectOption('site-selector', 'english');
        $I->amOnPage('/forms/multilang');
        $I->see('Full Name');

        // French version
        $I->selectOption('site-selector', 'french');
        $I->amOnPage('/forms/multilang');
        $I->see('Nom Complet');
    }

    /**
     * Scenario 8: Email subject in correct language
     */
    public function testEmailSubjectInCorrectLanguage(FunctionalTester $I)
    {
        // Submit on English site
        $I->selectOption('site-selector', 'english');
        $I->amOnPage('/forms/multilang');
        $I->submitForm(['field_name' => 'English Sub']);

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('New Contact Submission');

        // Submit on French site
        $I->selectOption('site-selector', 'french');
        $I->amOnPage('/forms/multilang');
        $I->submitForm(['field_name' => 'French Sub']);

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('Nouvelle Soumission de Contact');
    }

    /**
     * Scenario 9: Multi-site submissions list
     */
    public function testMultiSiteSubmissionsList(FunctionalTester $I)
    {
        // Submit to both sites
        $I->selectOption('site-selector', 'english');
        $I->amOnPage('/forms/multilang');
        $I->submitForm(['field_name' => 'English']);

        $I->selectOption('site-selector', 'french');
        $I->amOnPage('/forms/multilang');
        $I->submitForm(['field_name' => 'French']);

        // View submissions
        $I->amOnPage('/admin/simple-form/submissions');
        $I->see('English');
        $I->see('French');
    }

    /**
     * Scenario 10: Filter submissions by site
     */
    public function testFilterSubmissionsBySite(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->selectOption('site-filter', 'french');

        // Should only show French submissions
        $I->see('French');
        $I->dontSee('English');
    }

    /**
     * Scenario 11: Translate form description
     */
    public function testTranslateFormDescription(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        $I->fillField('description', 'English description');

        $I->selectOption('site-selector', 'french');
        $I->fillField('description', 'Description française');

        $I->click('Save');

        // Verify
        $I->selectOption('site-selector', 'english');
        $I->seeInField('description', 'English description');

        $I->selectOption('site-selector', 'french');
        $I->seeInField('description', 'Description française');
    }

    /**
     * Scenario 12: Regional form configurations
     */
    public function testRegionalFormConfigurations(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        // English email recipient
        $I->fillField('emailTo', 'en@example.com');

        // French email recipient
        $I->selectOption('site-selector', 'french');
        $I->fillField('emailTo', 'fr@example.com');

        $I->click('Save');

        // Verify submissions email to correct recipients
        $I->selectOption('site-selector', 'english');
        $I->amOnPage('/forms/multilang');
        $I->submitForm(['field_name' => 'English']);

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('en@example.com');

        // French submission
        $I->selectOption('site-selector', 'french');
        $I->amOnPage('/forms/multilang');
        $I->submitForm(['field_name' => 'French']);

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('fr@example.com');
    }
}

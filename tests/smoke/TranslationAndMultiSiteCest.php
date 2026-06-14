<?php

namespace fabianhaef\simpleform\tests\smoke;
use FunctionalTester;
class TranslationAndMultiSiteCest
{
    public function _before(FunctionalTester $I)
    {
        $I->loginAsAdmin();
    }

    public function testCreateFormWithEnglishTranslation(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');

        $I->fillField('name', 'Multi-Lang Form');
        $I->fillField('handle', 'multilang');
        $I->fillField('title', 'Contact Form (English)');

        $I->click('Save');
        $I->see('Contact Form (English)');
    }

    public function testTranslateFormToFrench(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        // Switch to French site
        $I->selectOption('siteId', 'french');

        $I->fillField('title', 'Formulaire de Contact (Français)');
        $I->click('Save');

        $I->see('Formulaire de Contact (Français)');
    }

    public function testVerifyEnglishAndFrenchTitlesCoexist(FunctionalTester $I)
    {
        // Switch to English
        $I->selectOption('siteId', 'english');
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        $I->seeInField('title', 'Contact Form (English)');

        // Switch to French
        $I->selectOption('siteId', 'french');
        $I->seeInField('title', 'Formulaire de Contact (Français)');
    }

    public function testTranslateFieldLabels(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        // Add field in English
        $I->click('Add Field');
        $I->fillField('label', 'Full Name');
        $I->fillField('handle', 'full_name');
        $I->selectOption('type', 'text');
        $I->click('Save Field');

        // Switch to French and translate
        $I->selectOption('siteId', 'french');
        $I->click('Edit Field', "//tr[contains(., 'Full Name')]");
        $I->fillField('label', 'Nom Complet');
        $I->click('Save Field');

        // Verify translations
        $I->selectOption('siteId', 'english');
        $I->see('Full Name');

        $I->selectOption('siteId', 'french');
        $I->see('Nom Complet');
    }

    public function testTranslateEmailSubject(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        // Set English subject
        $I->fillField('emailSubject', 'New Contact Submission');

        // Switch to French
        $I->selectOption('siteId', 'french');
        $I->fillField('emailSubject', 'Nouvelle Soumission de Contact');
        $I->click('Save');

        // Verify both exist
        $I->selectOption('siteId', 'english');
        $I->seeInField('emailSubject', 'New Contact Submission');

        $I->selectOption('siteId', 'french');
        $I->seeInField('emailSubject', 'Nouvelle Soumission de Contact');
    }

    public function testSubmissionRecordsSiteLanguage(FunctionalTester $I)
    {
        // Switch to French site
        $I->selectOption('siteId', 'french');

        $I->amOnPage('/forms/multilang');
        $I->fillField('full_name', 'French Submitter');
        $I->click('Submit');

        // Verify site recorded
        $I->seeInDatabase('simpleform_submissions', ['data' => '%French Submitter%']);
    }

    public function testFormRendersInCorrectLanguage(FunctionalTester $I)
    {
        // English version
        $I->selectOption('siteId', 'english');
        $I->amOnPage('/forms/multilang');
        $I->see('Full Name');

        // French version
        $I->selectOption('siteId', 'french');
        $I->amOnPage('/forms/multilang');
        $I->see('Nom Complet');
    }

    public function testEmailSubjectInCorrectLanguage(FunctionalTester $I)
    {
        // Submit on English site
        $I->selectOption('siteId', 'english');
        $I->amOnPage('/forms/multilang');
        $I->fillField('full_name', 'English Sub');
        $I->click('Submit');

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('New Contact Submission');

        // Submit on French site
        $I->selectOption('siteId', 'french');
        $I->amOnPage('/forms/multilang');
        $I->fillField('full_name', 'French Sub');
        $I->click('Submit');

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('Nouvelle Soumission de Contact');
    }

    public function testMultiSiteSubmissionsList(FunctionalTester $I)
    {
        // Submit to both sites
        $I->selectOption('siteId', 'english');
        $I->amOnPage('/forms/multilang');
        $I->fillField('full_name', 'English');
        $I->click('Submit');

        $I->selectOption('siteId', 'french');
        $I->amOnPage('/forms/multilang');
        $I->fillField('full_name', 'French');
        $I->click('Submit');

        // View submissions
        $I->amOnPage('/admin/simple-form/submissions');
        $I->see('English');
        $I->see('French');
    }

    public function testFilterSubmissionsBySite(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/submissions');
        $I->selectOption('siteFilter', 'french');

        // Should only show French submissions
        $I->seeElement('//tr[contains(., "French")]');
    }

    public function testTranslateFormDescription(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        $I->fillField('description', 'English description');

        $I->selectOption('siteId', 'french');
        $I->fillField('description', 'Description française');

        $I->click('Save');

        // Verify
        $I->selectOption('siteId', 'english');
        $I->seeInField('description', 'English description');

        $I->selectOption('siteId', 'french');
        $I->seeInField('description', 'Description française');
    }

    public function testRegionalFormConfigurations(FunctionalTester $I)
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('Multi-Lang Form');

        // English email recipient
        $I->fillField('emailTo', 'en@example.com');

        // French email recipient
        $I->selectOption('siteId', 'french');
        $I->fillField('emailTo', 'fr@example.com');

        $I->click('Save');

        // Verify submissions email to correct recipients
        $I->selectOption('siteId', 'english');
        $I->amOnPage('/forms/multilang');
        $I->fillField('full_name', 'English');
        $I->click('Submit');

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('en@example.com');

        // French submission
        $I->selectOption('siteId', 'french');
        $I->amOnPage('/forms/multilang');
        $I->fillField('full_name', 'French');
        $I->click('Submit');

        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('fr@example.com');
    }
}

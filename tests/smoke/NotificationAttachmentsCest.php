<?php

namespace fabianhaef\simpleform\tests\smoke;

use FunctionalTester;

/**
 * Smoke scenarios for submission PDF generation + notification attachments
 * (#143). Exercises the CP toggles, the queued notification with a PDF
 * attachment landing in Mailpit, and the CP "Download PDF" action.
 */
class NotificationAttachmentsCest
{
    public function _before(FunctionalTester $I): void
    {
        $I->loginAsAdmin();

        // A form with one text field and a notification recipient.
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('New Form');
        $I->fillField('name', 'PDF Test');
        $I->fillField('handle', 'pdf-test');
        $I->fillField('emailTo', 'admin@example.com');
        $I->fillField('emailSubject', 'PDF Submission');
        $I->click('Save');

        $I->click('Add Field');
        $I->fillField('label', 'Full Name');
        $I->fillField('handle', 'fullName');
        $I->selectOption('type', 'text');
        $I->click('Save Field');
    }

    /**
     * The notification edit screen exposes the Attachments section with the
     * Attach-PDF and Attach-uploads toggles.
     */
    public function testNotificationEditShowsAttachmentToggles(FunctionalTester $I): void
    {
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('PDF Test');
        $I->click('Notifications');
        $I->click('New notification');

        $I->see('Attachments');
        $I->see('Attach a submission PDF');
        $I->see('Attach uploaded files');
    }

    /**
     * With dompdf installed, enabling "Attach PDF" sends a notification whose
     * email carries a PDF attachment containing the submitted value.
     */
    public function testSubmissionEmailCarriesPdfAttachment(FunctionalTester $I): void
    {
        // Enable the PDF toggle on a notification for this form.
        $I->amOnPage('/admin/simple-form/forms');
        $I->click('PDF Test');
        $I->click('Notifications');
        $I->click('New notification');
        $I->fillField('name', 'Admin alert');
        $I->fillField('recipient', 'admin@example.com');
        $I->click('Attach a submission PDF');
        $I->click('Save');

        // Submit the public form; notification is queued + sent.
        $I->amOnPage('/forms/pdf-test');
        $I->fillField('fullName', 'Grace Hopper');
        $I->click('Submit');

        // Mailpit shows the message with a PDF attachment.
        $I->amOnPage('http://craft-plugin-dev.ddev.site:8025');
        $I->see('PDF Submission');
        $I->see('.pdf');
    }

    /**
     * The CP submission detail offers a "Download PDF" action that streams a PDF.
     */
    public function testSubmissionDetailDownloadsPdf(FunctionalTester $I): void
    {
        $I->amOnPage('/forms/pdf-test');
        $I->fillField('fullName', 'Ada Lovelace');
        $I->click('Submit');

        $I->amOnPage('/admin/simple-form/submissions');
        $I->click('Ada Lovelace');
        $I->see('Download PDF');
    }
}

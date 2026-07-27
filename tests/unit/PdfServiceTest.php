<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\pdf\DompdfEngine;
use anvildev\simpleform\pdf\PdfEngineInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Source/engine-level guards for the PDF feature (#143) that run without a
 * bootstrapped Craft application, matching the rest of the unit suite.
 */
class PdfServiceTest extends TestCase
{
    private function source(string $relativePath): string
    {
        $code = file_get_contents(__DIR__ . '/../../src/' . $relativePath);
        $this->assertNotFalse($code);
        return $code;
    }

    public function testDompdfEngineReportsAvailabilityByClassExists(): void
    {
        // dompdf is a require-dev dependency, so it is present in the test env and
        // the engine must report itself available + produce real PDF bytes.
        $engine = new DompdfEngine();
        $this->assertInstanceOf(PdfEngineInterface::class, $engine);
        $this->assertTrue($engine->isAvailable(), 'dompdf should be installed in the test environment');

        $pdf = $engine->renderHtml('<html><body><h1>Hello</h1></body></html>');
        $this->assertNotSame('', $pdf);
        // A PDF always starts with the %PDF- magic header.
        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function testEngineSeamIsGuardedByClassExists(): void
    {
        // The service must only instantiate the engine when its library exists, so
        // a missing optional dependency never fatals.
        $code = $this->source('services/PdfService.php');
        $this->assertStringContainsString('class_exists(\Dompdf\Dompdf::class)', $code);
        $this->assertStringContainsString('implements PdfEngineInterface', $this->source('pdf/DompdfEngine.php'));
    }

    public function testServiceRendersThroughSandboxedTemplate(): void
    {
        // The PDF body must be produced through the sandboxed SafeRenderService
        // seam (F2/SSTI), so an overriding pdf.twig cannot reach craft.app.
        $code = $this->source('services/PdfService.php');
        $this->assertStringContainsString('getSafeRender()->renderTemplate(', $code);
        $this->assertStringContainsString("public const TEMPLATE = 'simple-form/forms/notifications/pdf'", $code);
    }

    public function testRenderNeverThrows(): void
    {
        // render() must catch failures and return null so a notification send can
        // degrade to no attachment rather than dropping the email.
        $code = $this->source('services/PdfService.php');
        $this->assertStringContainsString('catch (\Throwable $e)', $code);
        $this->assertStringContainsString('return null;', $code);
    }

    public function testNotificationModelGuardsPdfToggleOnAvailability(): void
    {
        $code = $this->source('models/NotificationModel.php');
        // Enabling attachPdf without an engine must be rejected by validation.
        $this->assertStringContainsString('validatePdfAvailable', $code);
        $this->assertStringContainsString('getPdf()->isAvailable()', $code);

        $defaults = (new ReflectionClass(\anvildev\simpleform\models\NotificationModel::class))->getDefaultProperties();
        $this->assertFalse($defaults['attachPdf'], 'attachPdf should default to off');
        $this->assertFalse($defaults['attachUploads'], 'attachUploads should default to off');
    }

    public function testEmailServiceAttachesPdfAndUploads(): void
    {
        $code = $this->source('services/EmailService.php');
        // PDF bytes are attached via attachContent with the application/pdf type.
        $this->assertStringContainsString('attachContent(', $code);
        $this->assertStringContainsString("'application/pdf'", $code);
        // The toggles gate the attachment set.
        $this->assertStringContainsString('attachPdf', $code);
        $this->assertStringContainsString('attachUploads', $code);
        // Uploads over the size cap fall back (skip + warn).
        $this->assertStringContainsString('maxAttachmentSizeMb', $code);
    }

    public function testNotificationSendIsQueued(): void
    {
        // PDF rendering must happen on the queue worker, not the submit request.
        $submission = $this->source('services/SubmissionService.php');
        $this->assertStringContainsString('queueForSubmission(', $submission);

        $email = $this->source('services/EmailService.php');
        $this->assertStringContainsString('SendNotifications(', $email);
    }
}

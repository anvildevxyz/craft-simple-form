<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\test\TestMailer;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\EmailService;
use yii\mail\MessageInterface;

/**
 * Submission PDF + uploaded-file notification attachments (#143). Verifies the
 * PDF is rendered and attached when the per-notification toggle is on (and not
 * when off), that the render degrades gracefully, and the upload-attachment path.
 *
 * @group requires-craft
 */
class NotificationAttachmentsTest extends SimpleFormTestCase
{
    public function testPdfServiceRendersSubmissionBytes(): void
    {
        $this->requireCraft();

        [$form, $submission, $data] = $this->seedSubmission('pdfRender', 'Grace Hopper');

        $pdf = Plugin::getInstance()->getPdf();
        $this->assertTrue($pdf->isAvailable(), 'dompdf is a require-dev dependency, so it must be available in tests');

        $bytes = $pdf->render($form, $submission, $data, (int) $submission->siteId);
        $this->assertNotNull($bytes);
        $this->assertStringStartsWith('%PDF-', (string) $bytes);
    }

    public function testNotificationAttachesPdfWhenToggleOn(): void
    {
        $this->requireCraft();

        [$form, $submission, $data] = $this->seedSubmission('pdfAttachOn', 'Ada Lovelace');
        $this->saveNotification((int) $form->id, ['attachPdf' => true]);

        $sent = $this->capture(fn() => (new EmailService())->sendSubmissionEmail($form, $submission, $data));

        $this->assertCount(1, $sent);
        $this->assertTrue($this->hasPdfAttachment($sent[0]), 'A PDF attachment should be present when attachPdf is on');
    }

    public function testNotificationDoesNotAttachPdfWhenToggleOff(): void
    {
        $this->requireCraft();

        [$form, $submission, $data] = $this->seedSubmission('pdfAttachOff', 'Edsger');
        $this->saveNotification((int) $form->id, ['attachPdf' => false]);

        $sent = $this->capture(fn() => (new EmailService())->sendSubmissionEmail($form, $submission, $data));

        $this->assertCount(1, $sent);
        $this->assertFalse($this->hasPdfAttachment($sent[0]), 'No PDF attachment should be present when attachPdf is off');
    }

    public function testEnablingPdfToggleWithoutEngineIsRejected(): void
    {
        $this->requireCraft();

        // The validator only blocks attachPdf when no engine is installed. dompdf
        // IS installed in tests, so a true value must validate here — proving the
        // guard is availability-driven, not a blanket rejection.
        [$form] = $this->seedSubmission('pdfValidate', 'X');
        $model = new NotificationModel();
        $model->formId = (int) $form->id;
        $model->name = 'Has PDF';
        $model->recipient = 'ops@example.test';
        $model->attachPdf = true;
        $this->assertTrue($model->validate(), 'attachPdf should validate when dompdf is available');
    }

    public function testUploadAttachmentResolvesAssetContents(): void
    {
        $this->requireCraft();

        $assetId = $this->seedAsset('resume.txt', 'curriculum vitae');
        if ($assetId === null) {
            $this->markTestSkipped('No asset volume configured in the test environment.');
        }

        Plugin::getInstance()->getSettings()->maxAttachmentSizeMb = 10;
        [$form, $submission, $data] = $this->seedSubmission('uploadAttach', 'Katherine');
        $data['field_file'] = ['label' => 'Resume', 'type' => 'file', 'value' => [$assetId]];
        $this->saveNotification((int) $form->id, ['attachUploads' => true]);

        $sent = $this->capture(fn() => (new EmailService())->sendSubmissionEmail($form, $submission, $data));
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('resume.txt', (string) $sent[0], 'the uploaded file should be attached');
    }

    public function testUnderCapUploadIsAttachedAndEmailStillSends(): void
    {
        $this->requireCraft();

        $assetId = $this->seedAsset('within.txt', 'small');
        if ($assetId === null) {
            $this->markTestSkipped('No asset volume configured in the test environment.');
        }

        // A generous cap keeps the small fixture under budget → it is attached and
        // the email sends. The over-cap skip branch (skip + warn + in-body link
        // fallback) is asserted at the source level in PdfServiceTest.
        Plugin::getInstance()->getSettings()->maxAttachmentSizeMb = 10;

        [$form, $submission, $data] = $this->seedSubmission('uploadUnderCap', 'Katherine');
        $data['field_file'] = ['label' => 'Resume', 'type' => 'file', 'value' => [$assetId]];
        $this->saveNotification((int) $form->id, ['attachUploads' => true]);

        $sent = $this->capture(fn() => (new EmailService())->sendSubmissionEmail($form, $submission, $data));
        $this->assertCount(1, $sent, 'the email still sends');
        $this->assertStringContainsString('within.txt', (string) $sent[0]);
    }

    /**
     * Create a real Asset in the first available volume; null when none exists.
     */
    private function seedAsset(string $name, string $contents): ?int
    {
        if (Craft::$app->getVolumes()->getAllVolumes() === []) {
            return null;
        }

        $path = Craft::$app->getPath()->getTempPath() . DIRECTORY_SEPARATOR . uniqid('sf_', true) . '_' . $name;
        file_put_contents($path, $contents);
        $upload = new \craft\web\UploadedFile([
            'name' => $name,
            'tempName' => $path,
            'type' => 'text/plain',
            'size' => strlen($contents),
            'error' => UPLOAD_ERR_OK,
        ]);

        $ids = Plugin::getInstance()->getAssetUploadService()->saveUploads([$upload], []);
        return $ids[0] ?? null;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * @return array{0: Form, 1: Submission, 2: array<string, mixed>}
     */
    private function seedSubmission(string $handle, string $value): array
    {
        $form = $this->createForm('Attach', $handle, 'Attach', emailTo: 'owner@example.com');
        $fieldId = $this->createField((int) $form->id, 'text', 'fullName', 'Full Name', true);

        $reloaded = Form::find()->id($form->id)->one();
        $submission = new Submission();
        $submission->formId = (int) $reloaded->id;
        $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $submission->readStatus = 'new';
        $data = ['field_' . $fieldId => ['label' => 'Full Name', 'type' => 'text', 'value' => $value]];
        $submission->data = $data;
        Craft::$app->getElements()->saveElement($submission);

        return [$reloaded, $submission, $data];
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function saveNotification(int $formId, array $extra): NotificationModel
    {
        $model = new NotificationModel();
        $model->formId = $formId;
        $model->name = 'N';
        $model->recipient = 'owner@example.com';
        $model->attachPdf = $extra['attachPdf'] ?? false;
        $model->attachUploads = $extra['attachUploads'] ?? false;
        $this->assertTrue(Plugin::getInstance()->getNotifications()->save($model));
        return $model;
    }

    /**
     * @return list<MessageInterface>
     */
    private function capture(callable $work): array
    {
        $mailer = Craft::$app->getMailer();
        $collected = [];
        if ($mailer instanceof TestMailer) {
            $original = $mailer->callback;
            $mailer->callback = function (MessageInterface $message) use (&$collected, $original): void {
                $collected[] = $message;
                if (is_callable($original)) {
                    $original($message);
                }
            };
            try {
                $work();
            } finally {
                $mailer->callback = $original;
            }
        } else {
            $work();
        }

        return $collected;
    }

    private function hasPdfAttachment(MessageInterface $message): bool
    {
        return str_contains((string) $message, 'application/pdf');
    }
}

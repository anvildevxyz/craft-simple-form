<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\helpers\App;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\jobs\SendNotifications;
use fabianhaef\simpleform\models\FieldModel;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * @phpstan-import-type SubmissionData from Submission
 */
class EmailService extends Component
{
    /**
     * Send every notification that fires for this submission. When the form has
     * no notification rows, fall back to its legacy email columns so existing
     * forms keep working unchanged.
     *
     * @param SubmissionData $data
     */
    public function sendSubmissionEmail(Form $form, Submission $submission, array $data): bool
    {
        $resolved = Plugin::getInstance()->getNotifications()->resolveForSubmission($form, $submission, $data);

        if ($resolved === []) {
            return $this->sendLegacy($form, $submission, $data);
        }

        $allSent = true;
        foreach ($resolved as $entry) {
            $notification = $entry['notification'];
            $sent = $this->send(
                $entry['recipients'],
                $this->renderSubjectFor($notification->subject, $form),
                $this->renderBodyFor($notification->body, $form, $submission, $data),
                $notification->replyTo,
                $this->attachmentsFor($notification, $form, $submission, $data),
            );
            $allSent = $allSent && $sent;
        }

        return $allSent;
    }

    /**
     * Build the attachment set for one notification (#143): the rendered
     * submission PDF when `attachPdf`, plus the submission's uploaded files when
     * `attachUploads` and they fit under the configured total-size cap. Over the
     * cap the uploads are skipped (and logged) — they remain available as in-body
     * download links.
     *
     * @param array<string, mixed> $data
     * @return list<array{content: string, fileName: string, contentType: string}>
     */
    private function attachmentsFor(NotificationModel $notification, Form $form, Submission $submission, array $data): array
    {
        $attachments = [];
        $totalBytes = 0;
        $capBytes = max(0, Plugin::getInstance()->getSettings()->maxAttachmentSizeMb) * 1024 * 1024;

        if ($notification->attachPdf) {
            $pdf = Plugin::getInstance()->getPdf()->render($form, $submission, $data, (int) $submission->siteId);
            if ($pdf !== null) {
                $attachments[] = [
                    'content' => $pdf,
                    'fileName' => Plugin::getInstance()->getPdf()->filename($form, $submission),
                    'contentType' => 'application/pdf',
                ];
                $totalBytes += strlen($pdf);
            }
        }

        if ($notification->attachUploads) {
            foreach ($this->uploadAttachments($data) as $upload) {
                $size = strlen($upload['content']);
                if ($capBytes > 0 && $totalBytes + $size > $capBytes) {
                    Craft::warning(sprintf(
                        'Skipping upload attachment "%s" for submission %d: over the %d MB attachment cap; sent as an in-body link instead.',
                        $upload['fileName'],
                        (int) $submission->id,
                        Plugin::getInstance()->getSettings()->maxAttachmentSizeMb,
                    ), 'simple-form');
                    continue;
                }
                $attachments[] = $upload;
                $totalBytes += $size;
            }
        }

        return $attachments;
    }

    /**
     * Resolve the submission's file-field uploads to attachment payloads.
     *
     * @param array<string, mixed> $data
     * @return list<array{content: string, fileName: string, contentType: string}>
     */
    private function uploadAttachments(array $data): array
    {
        $attachments = [];
        foreach ($data as $fieldData) {
            if (!is_array($fieldData) || ($fieldData['type'] ?? null) !== 'file') {
                continue;
            }
            $ids = is_array($fieldData['value'] ?? null) ? $fieldData['value'] : [];
            foreach ($ids as $id) {
                $asset = \craft\elements\Asset::find()->id((int) $id)->one();
                if (!$asset instanceof \craft\elements\Asset) {
                    continue;
                }
                try {
                    $contents = $asset->getContents();
                } catch (\Throwable $e) {
                    Craft::warning('Failed to read upload for attachment: ' . $e->getMessage(), 'simple-form');
                    continue;
                }
                $attachments[] = [
                    'content' => $contents,
                    'fileName' => (string) $asset->getFilename(),
                    'contentType' => $asset->getMimeType() ?? 'application/octet-stream',
                ];
            }
        }

        return $attachments;
    }

    /**
     * Queue a submission's notification emails for off-request sending (#143).
     * Composing can render a PDF / read uploaded files, so it must not run inline
     * in the visitor's submit request. Falls back to inline sending only when the
     * `dispatchIntegrationsSynchronously` debug escape hatch is on.
     *
     * @param array<string, mixed> $data
     */
    public function queueForSubmission(Form $form, Submission $submission, array $data): void
    {
        if ($this->getSettings()->dispatchIntegrationsSynchronously || $submission->id === null) {
            $this->sendSubmissionEmail($form, $submission, $data);
            return;
        }

        Craft::$app->getQueue()->push(new SendNotifications([
            'submissionId' => (int) $submission->id,
        ]));
    }

    /**
     * Legacy single-notification path driven by the form's own email columns.
     *
     * @param SubmissionData $data
     */
    private function sendLegacy(Form $form, Submission $submission, array $data): bool
    {
        if (!$form->emailTo) {
            return false;
        }

        return $this->send(
            $form->emailTo,
            $this->renderSubjectFor($form->emailSubject, $form),
            $this->renderBodyFor($form->emailBody, $form, $submission, $data),
            $form->emailReplyTo,
        );
    }

    /**
     * Compose + send one email.
     *
     * @param list<string>|string $to
     * @param list<array{content: string, fileName: string, contentType: string}> $attachments
     */
    private function send(array|string $to, string $subject, string $body, ?string $replyTo, array $attachments = []): bool
    {
        if ($to === [] || $to === '') {
            return false;
        }

        try {
            $mail = Craft::$app->getMailer()
                ->compose()
                ->setTo($to)
                ->setSubject($subject)
                ->setHtmlBody($body);

            foreach ($attachments as $attachment) {
                $mail->attachContent($attachment['content'], [
                    'fileName' => $attachment['fileName'],
                    'contentType' => $attachment['contentType'],
                ]);
            }

            // Set from address: prefer the plugin's configured sender, falling
            // back to Craft's system email settings.
            $mailSettings = App::mailSettings();
            $parsedFromEmail = App::parseEnv($mailSettings->fromEmail);
            $parsedFromName = App::parseEnv($mailSettings->fromName);
            $fromEmail = $this->getSettings()->getSenderEmail() ?? (is_string($parsedFromEmail) ? $parsedFromEmail : null);
            $fromName = $this->getSettings()->getSenderName() ?? (is_string($parsedFromName) ? $parsedFromName : null);
            if ($fromEmail) {
                $mail->setFrom($fromName ? [$fromEmail => $fromName] : $fromEmail);
            }

            if ($replyTo) {
                $mail->setReplyTo($replyTo);
            }

            return $mail->send();
        } catch (\Exception $e) {
            Craft::warning('Failed to send form submission email: ' . $e->getMessage(), 'simple-form');
            return false;
        }
    }

    private function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }

    private function renderSubjectFor(?string $subject, Form $form): string
    {
        if ($subject !== null && trim($subject) !== '') {
            return $subject;
        }
        return Craft::t('simple-form', 'New Submission: {formTitle}', [
            'formTitle' => $form->title ?? $form->name,
        ]);
    }

    /**
     * Render a notification body template (per-site so it localises), falling
     * back to the shared default template when blank or on a render error.
     *
     * @param SubmissionData $data
     */
    private function renderBodyFor(?string $body, Form $form, Submission $submission, array $data): string
    {
        if ($body !== null && trim($body) !== '') {
            try {
                return $this->renderSandboxed($body, [
                    'form' => $form,
                    'submission' => $submission,
                    'data' => $data,
                ]);
            } catch (\Throwable $e) {
                Craft::warning('Failed to render notification body, using default: ' . $e->getMessage(), 'simple-form');
            }
        }

        return Plugin::getInstance()->getSubmissionBodyRenderer()->render($form, $submission, $data);
    }

    /**
     * Render an admin-authored Twig body string with the Twig sandbox FORCED on
     * (audit finding F2, CWE-94 / SSTI).
     *
     * Notification bodies are editable by CP users holding only `manageForms` —
     * a non-admin permission — so they must NOT be able to reach `craft.app.*`,
     * the database, the filesystem or arbitrary classes through the template.
     * The form, submission and field models are additionally allowed so
     * legitimate templates like `{{ submission.id }}` or
     * `{% for f in form.fields %}` keep working. Delegates to the shared
     * {@see SafeRenderService} seam.
     *
     * @param array<string, mixed> $variables
     * @throws \Throwable when the sandbox rejects the template or rendering fails
     */
    private function renderSandboxed(string $template, array $variables): string
    {
        return Plugin::getInstance()->getSafeRender()->render(
            $template,
            $variables,
            [Form::class, Submission::class, FieldModel::class],
        );
    }
}

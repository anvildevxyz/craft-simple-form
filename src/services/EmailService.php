<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\mail\Message;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use yii\base\Component;

class EmailService extends Component
{
    public function sendSubmissionEmail(Form $form, Submission $submission, array $data): bool
    {
        if (!$form->emailTo) {
            return false;
        }

        try {
            $subject = $this->renderSubject($form);
            $body = $this->renderBody($form, $submission, $data);

            $mail = Craft::$app->getMailer()
                ->compose()
                ->setTo($form->emailTo)
                ->setSubject($subject)
                ->setHtmlBody($body);

            // Set from address
            $fromEmail = Craft::$app->getSystemSettings()->getEmailFromEmail();
            $fromName = Craft::$app->getSystemSettings()->getEmailFromName();
            $mail->setFrom("$fromName <$fromEmail>");

            // Set reply-to if configured
            if ($form->emailReplyTo) {
                $mail->setReplyTo($form->emailReplyTo);
            }

            return $mail->send();
        } catch (\Exception $e) {
            Craft::warning('Failed to send form submission email: ' . $e->getMessage(), 'simple-form');
            return false;
        }
    }

    private function renderSubject(Form $form): string
    {
        // Use configured subject or fallback
        if ($form->emailSubject) {
            return $form->emailSubject;
        }
        return Craft::t('simple-form', 'New Submission: {formTitle}', [
            'formTitle' => $form->title ?? $form->name,
        ]);
    }

    private function renderBody(Form $form, Submission $submission, array $data): string
    {
        $html = '<html><body>';
        $html .= '<h2>' . Craft::t('simple-form', 'New Form Submission') . '</h2>';

        // Form information
        $html .= '<p>';
        $html .= '<strong>' . Craft::t('simple-form', 'Form') . ':</strong> ' . htmlspecialchars($form->title ?? $form->name) . '<br>';
        $html .= '<strong>' . Craft::t('simple-form', 'Date') . ':</strong> ' . $submission->dateCreated->format('Y-m-d H:i:s') . '<br>';

        if ($submission->userId) {
            $user = Craft::$app->getUsers()->getUserById($submission->userId);
            if ($user) {
                $html .= '<strong>' . Craft::t('simple-form', 'User') . ':</strong> ' . htmlspecialchars($user->fullName ?: $user->username) . '<br>';
            }
        }

        $html .= '</p>';

        // Submission data
        $html .= '<hr>';
        $html .= '<h3>' . Craft::t('simple-form', 'Submission Data') . '</h3>';
        $html .= '<table style="border-collapse: collapse; width: 100%;">';

        foreach ($data as $fieldData) {
            $label = htmlspecialchars($fieldData['label'] ?? '');
            $value = $this->formatFieldValue($fieldData['value']);

            $html .= '<tr style="border-bottom: 1px solid #ddd;">';
            $html .= '<td style="padding: 10px; font-weight: bold; width: 30%;">' . $label . '</td>';
            $html .= '<td style="padding: 10px;">' . $value . '</td>';
            $html .= '</tr>';
        }

        $html .= '</table>';

        // Footer
        $html .= '<hr>';
        $html .= '<p style="font-size: 0.9em; color: #666;">';
        $html .= Craft::t('simple-form', 'This is an automated message. Please do not reply directly to this email.');
        $html .= '</p>';

        $html .= '</body></html>';

        return $html;
    }

    private function formatFieldValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '<em style="color: #999;">—</em>';
        }

        if (is_array($value)) {
            $stringValues = array_map('strval', $value);
            return htmlspecialchars(implode(', ', $stringValues));
        }

        return htmlspecialchars((string) $value);
    }
}

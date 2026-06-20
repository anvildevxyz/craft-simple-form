<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\helpers\App;
use craft\web\twig\SecurityPolicy;
use craft\web\View;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\FieldModel;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\Plugin;
use Twig\Extension\SandboxExtension;
use yii\base\Component;

class EmailService extends Component
{
    /**
     * Send every notification that fires for this submission. When the form has
     * no notification rows, fall back to its legacy email columns so existing
     * forms keep working unchanged.
     *
     * @param array<string, mixed> $data
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
            );
            $allSent = $allSent && $sent;
        }

        return $allSent;
    }

    /**
     * Legacy single-notification path driven by the form's own email columns.
     *
     * @param array<string, mixed> $data
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
     */
    private function send(array|string $to, string $subject, string $body, ?string $replyTo): bool
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
     * @param array<string, mixed> $data
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

        return $this->renderDefaultBody($form, $submission, $data);
    }

    /**
     * Render an admin-authored Twig body string with the Twig sandbox FORCED on
     * (audit finding F2, CWE-94 / SSTI).
     *
     * Notification bodies are editable by CP users holding only `manageForms` —
     * a non-admin permission — so they must NOT be able to reach `craft.app.*`,
     * the database, the filesystem or arbitrary classes through the template.
     * We cannot rely on Craft's `renderSandboxedString()`: it is a no-op unless
     * the operator sets the global `enableTwigSandbox` config (default false).
     *
     * Instead we explicitly enable the SandboxExtension for this one render and
     * swap in a policy derived from Craft's own twig-sandbox config (safe tags /
     * filters / functions) but additionally allowing this plugin's own form,
     * submission and field models so legitimate templates like
     * `{{ submission.id }}` or `{% for f in form.fields %}` keep working. The
     * original policy and sandbox state are always restored.
     *
     * @param array<string, mixed> $variables
     */
    private function renderSandboxed(string $template, array $variables): string
    {
        $view = Craft::$app->getView();
        $twig = $view->getTwig(View::TEMPLATE_MODE_SITE);
        /** @var SandboxExtension $sandbox */
        $sandbox = $twig->getExtension(SandboxExtension::class);

        $base = $sandbox->getSecurityPolicy();
        $allowedClasses = [Form::class, Submission::class, FieldModel::class];
        if ($base instanceof SecurityPolicy) {
            $allowedClasses = array_merge($base->getAllowedClasses(), $allowedClasses);
        }

        $scoped = new SecurityPolicy(
            $base instanceof SecurityPolicy ? $base->getAllowedTags() : [],
            $base instanceof SecurityPolicy ? $base->getAllowedFilters() : [],
            $base instanceof SecurityPolicy ? $base->getAllowedFunctions() : [],
            $base instanceof SecurityPolicy ? $base->getAllowedMethods() : [],
            $base instanceof SecurityPolicy ? $base->getAllowedProperties() : [],
            $allowedClasses,
        );

        $wasSandboxed = $sandbox->isSandboxed();
        $sandbox->setSecurityPolicy($scoped);
        $sandbox->enableSandbox();
        try {
            return $view->renderString($template, $variables, View::TEMPLATE_MODE_SITE);
        } finally {
            if (!$wasSandboxed) {
                $sandbox->disableSandbox();
            }
            $sandbox->setSecurityPolicy($base);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderDefaultBody(Form $form, Submission $submission, array $data): string
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
            $value = ($fieldData['type'] ?? null) === 'file'
                ? $this->formatFileValue($fieldData['value'])
                : $this->formatFieldValue($fieldData['value']);

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

    /**
     * Render a file field's stored asset ids as download links (filename + URL).
     *
     * @param mixed $value list of asset ids
     */
    private function formatFileValue(mixed $value): string
    {
        $ids = is_array($value) ? $value : [];
        if ($ids === []) {
            return '<em style="color: #999;">—</em>';
        }

        $links = [];
        foreach ($ids as $id) {
            $asset = \craft\elements\Asset::find()->id((int) $id)->one();
            if (!$asset instanceof \craft\elements\Asset) {
                continue;
            }
            $url = $asset->getUrl();
            $name = htmlspecialchars((string) $asset->getFilename());
            $links[] = $url
                ? '<a href="' . htmlspecialchars((string) $url) . '">' . $name . '</a>'
                : $name;
        }

        return $links === [] ? '<em style="color: #999;">—</em>' : implode('<br>', $links);
    }
}

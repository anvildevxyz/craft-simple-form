<?php

namespace fabianhaef\simpleform\integrations;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\SafeUrl;
use fabianhaef\simpleform\integrations\support\ApiConnector;
use fabianhaef\simpleform\integrations\support\SubmissionValues;

/**
 * Shared base for chat/notification connectors (Slack, Discord) that POST a JSON
 * message to an incoming-webhook URL. Subclasses supply the provider-specific
 * payload shape via {@see buildPayload()}; this base owns the transport and the
 * message composition (auto field list, or a `{handle}` placeholder template).
 * The HTTP/SSRF plumbing comes from {@see ApiConnector}.
 */
abstract class AbstractChatIntegration implements IntegrationTypeInterface
{
    use ApiConnector;

    abstract public static function handle(): string;

    abstract public static function displayName(): string;

    abstract public function settingsHtml(array $settings): string;

    /**
     * The provider-specific JSON body for a submission.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    abstract public function buildPayload(Submission $submission, array $settings): array;

    public function defineSettingsRules(): array
    {
        return [
            [['url'], 'required'],
            [['url'], 'string'],
            [['url'], function($attribute, $params, $validator, $value): void {
                if (is_string($value) && !SafeUrl::isAcceptableSettingUrl($value)) {
                    $this->addError($attribute, Craft::t('simple-form', 'The URL must be a public http(s) address.'));
                }
            }],
        ];
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $url = trim((string) ($settings['url'] ?? ''));
        if ($url === '') {
            return IntegrationResult::failure(null, 'No webhook URL configured');
        }

        return $this->post($url, $this->buildPayload($submission, $settings));
    }

    /**
     * POST a JSON payload to the webhook URL. Isolated so it can be exercised
     * with a mocked client; the SSRF guard, transport-exception trap, and
     * response mapping come from {@see ApiConnector::request()}.
     *
     * @param array<string, mixed> $payload
     */
    protected function post(string $url, array $payload): IntegrationResult
    {
        return $this->request('POST', $url, ['json' => $payload]);
    }

    /**
     * Compose the human-readable message: a `{handle}` placeholder template when
     * configured, otherwise an auto-generated header + field list.
     *
     * @param array<string, mixed> $settings
     */
    protected function composeMessage(Submission $submission, array $settings): string
    {
        $template = trim((string) ($settings['messageTemplate'] ?? ''));
        if ($template !== '') {
            return self::applyTemplate($template, SubmissionValues::byHandle($submission));
        }

        $form = $submission->getForm();
        $header = Craft::t('simple-form', 'New submission: {form}', [
            'form' => $form?->title ?? $form?->handle ?? 'form',
        ]);
        $lines = SubmissionValues::labelledLines($submission);

        return $lines === [] ? $header : $header . "\n" . implode("\n", $lines);
    }

    /**
     * Replace `{handle}` tokens with submitted values (arrays joined by ", ";
     * unknown handles become empty).
     *
     * @param array<string, mixed> $values
     */
    public static function applyTemplate(string $template, array $values): string
    {
        return preg_replace_callback('/\{(\w+)\}/', static function(array $m) use ($values): string {
            $value = $values[$m[1]] ?? '';
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            return (string) $value;
        }, $template) ?? $template;
    }
}

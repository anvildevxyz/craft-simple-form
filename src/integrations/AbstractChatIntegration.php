<?php

namespace fabianhaef\simpleform\integrations;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\SafeUrl;
use fabianhaef\simpleform\integrations\support\SubmissionValues;
use GuzzleHttp\Client;

/**
 * Shared base for chat/notification connectors (Slack, Discord) that POST a JSON
 * message to an incoming-webhook URL. Subclasses supply the provider-specific
 * payload shape via {@see buildPayload()}; this base owns the transport and the
 * message composition (auto field list, or a `{handle}` placeholder template).
 */
abstract class AbstractChatIntegration implements IntegrationTypeInterface
{
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
     * with a mocked client.
     *
     * @param array<string, mixed> $payload
     */
    protected function post(string $url, array $payload): IntegrationResult
    {
        // SSRF guard (F3): only dispatch to a public address.
        if (!SafeUrl::isPublicHttpUrl($url)) {
            return IntegrationResult::failure(null, 'Blocked request to a non-public address');
        }

        try {
            $response = $this->httpClient()->request('POST', $url, [
                'json' => $payload,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            return IntegrationResult::failure(null, $e->getMessage());
        }

        $code = $response->getStatusCode();
        if ($code >= 200 && $code < 300) {
            return IntegrationResult::success($code, 'OK');
        }

        return IntegrationResult::failure($code, substr((string) $response->getBody(), 0, 500));
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

    protected function httpClient(): Client
    {
        return Craft::createGuzzleClient([
            'timeout' => 10,
            'connect_timeout' => 5,
            // Don't follow redirects (F3): a public URL must not be able to
            // 30x-bounce the request to an internal host.
            'allow_redirects' => false,
        ]);
    }
}

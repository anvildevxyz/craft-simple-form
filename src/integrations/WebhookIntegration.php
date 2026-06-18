<?php

namespace fabianhaef\simpleform\integrations;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Json;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\FormModel;
use GuzzleHttp\Client;

/**
 * Generic outbound webhook — POST/PUT a JSON or form-encoded payload to a target
 * URL, optionally HMAC-signed. The reference connector for the integrations
 * framework; covers Zapier/Make/n8n and any custom endpoint.
 *
 * Settings: `url` (env-aware), `method` (POST|PUT), `format` (json|form),
 * `secret` (env-aware; enables signing), `fieldMapping` (optional
 * fieldHandle => payloadKey map; default sends every field keyed by handle).
 */
class WebhookIntegration implements IntegrationTypeInterface
{
    public const SIGNATURE_HEADER = 'X-SimpleForm-Signature';

    public static function handle(): string
    {
        return 'webhook';
    }

    public static function displayName(): string
    {
        return 'Webhook';
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function settingsHtml(array $settings): string
    {
        return Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'Webhook URL'),
            'instructions' => Craft::t('simple-form', 'Where to send each submission. Supports environment variables.'),
            'id' => 'url',
            'name' => 'url',
            'value' => $settings['url'] ?? '',
            'suggestEnvVars' => true,
            'required' => true,
        ]) . Cp::selectFieldHtml([
            'label' => Craft::t('simple-form', 'HTTP method'),
            'id' => 'method',
            'name' => 'method',
            'options' => [
                ['label' => 'POST', 'value' => 'POST'],
                ['label' => 'PUT', 'value' => 'PUT'],
            ],
            'value' => $settings['method'] ?? 'POST',
        ]) . Cp::selectFieldHtml([
            'label' => Craft::t('simple-form', 'Payload format'),
            'id' => 'format',
            'name' => 'format',
            'options' => [
                ['label' => Craft::t('simple-form', 'JSON'), 'value' => 'json'],
                ['label' => Craft::t('simple-form', 'Form-encoded'), 'value' => 'form'],
            ],
            'value' => $settings['format'] ?? 'json',
        ]) . Cp::autosuggestFieldHtml([
            'label' => Craft::t('simple-form', 'Signing secret'),
            'instructions' => Craft::t('simple-form', 'Optional. When set, the body is HMAC-SHA256 signed in the {header} header. Supports environment variables.', ['header' => self::SIGNATURE_HEADER]),
            'id' => 'secret',
            'name' => 'secret',
            'value' => $settings['secret'] ?? '',
            'suggestEnvVars' => true,
        ]);
    }

    public function defineSettingsRules(): array
    {
        return [
            [['url'], 'required'],
            [['url'], 'string'],
            [['method'], 'in', 'range' => ['POST', 'PUT']],
            [['format'], 'in', 'range' => ['json', 'form']],
        ];
    }

    public function send(Submission $submission, array $settings): IntegrationResult
    {
        $url = trim((string) ($settings['url'] ?? ''));
        if ($url === '') {
            return IntegrationResult::failure(null, 'No webhook URL configured');
        }

        $method = strtoupper((string) ($settings['method'] ?? 'POST'));
        if (!in_array($method, ['POST', 'PUT'], true)) {
            $method = 'POST';
        }

        $payload = $this->buildPayload($submission, $settings);

        if (($settings['format'] ?? 'json') === 'form') {
            $body = http_build_query($payload);
            $contentType = 'application/x-www-form-urlencoded';
        } else {
            $body = Json::encode($payload);
            $contentType = 'application/json';
        }

        $secret = trim((string) ($settings['secret'] ?? ''));

        return $this->requestWebhook($method, $url, $body, $contentType, $secret !== '' ? $secret : null);
    }

    /**
     * The HTTP transport, isolated so it can be exercised with a mocked client.
     *
     * @internal
     */
    public function requestWebhook(string $method, string $url, string $body, string $contentType, ?string $secret): IntegrationResult
    {
        $headers = ['Content-Type' => $contentType];
        if ($secret !== null) {
            $headers[self::SIGNATURE_HEADER] = self::signBody($body, $secret);
        }

        try {
            $response = $this->httpClient()->request($method, $url, [
                'headers' => $headers,
                'body' => $body,
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

    /** HMAC-SHA256 of the raw body, formatted `sha256=<hex>`. */
    public static function signBody(string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $body, $secret);
    }

    /**
     * Build the outbound payload: form/submission metadata plus a `data` object
     * keyed by field handle. When a `fieldMapping` is configured, only the mapped
     * fields are sent, renamed to their target keys.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    public function buildPayload(Submission $submission, array $settings): array
    {
        $byHandle = $this->valuesByHandle($submission);

        $mapping = $settings['fieldMapping'] ?? [];
        if (is_array($mapping) && $mapping !== []) {
            $values = [];
            foreach ($mapping as $handle => $payloadKey) {
                if (!is_string($payloadKey) || $payloadKey === '') {
                    continue;
                }
                $values[$payloadKey] = $byHandle[$handle] ?? null;
            }
        } else {
            $values = $byHandle;
        }

        $form = $submission->getForm();

        return [
            'formHandle' => $form?->handle,
            'submissionId' => $submission->id !== null ? (int) $submission->id : null,
            'submissionUid' => $submission->uid,
            'siteId' => $submission->siteId !== null ? (int) $submission->siteId : null,
            'dateCreated' => $submission->dateCreated?->format(\DateTimeInterface::ATOM),
            'data' => $values,
        ];
    }

    /**
     * Flatten the stored submission data to a `handle => value` map. Falls back
     * to the raw `field_<id>` key when the field handle can't be resolved.
     *
     * @return array<string, mixed>
     */
    private function valuesByHandle(Submission $submission): array
    {
        $handleByKey = [];
        $form = $submission->getForm();
        if ($form !== null) {
            foreach ((new FormModel($form))->getFields() as $fieldId => $field) {
                $handleByKey['field_' . $fieldId] = $field->getName();
            }
        }

        $out = [];
        foreach ($submission->data ?? [] as $key => $entry) {
            $value = is_array($entry) ? ($entry['value'] ?? null) : $entry;
            $out[$handleByKey[$key] ?? $key] = $value;
        }
        return $out;
    }

    protected function httpClient(): Client
    {
        return Craft::createGuzzleClient([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
    }
}

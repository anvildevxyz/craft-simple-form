<?php

namespace anvildev\simpleform\integrations;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SafeUrl;
use anvildev\simpleform\integrations\support\ApiConnector;
use anvildev\simpleform\integrations\support\SubmissionValues;
use Craft;
use craft\helpers\Cp;
use craft\helpers\Json;

/**
 * Generic outbound webhook — POST/PUT a JSON or form-encoded payload to a target
 * URL, optionally HMAC-signed. The reference connector for the integrations
 * framework; covers Zapier/Make/n8n and any custom endpoint. The HTTP/SSRF
 * plumbing comes from {@see ApiConnector}.
 *
 * Settings: `url` (env-aware), `method` (POST|PUT), `format` (json|form),
 * `secret` (env-aware; enables signing), `fieldMapping` (optional
 * fieldHandle => payloadKey map; default sends every field keyed by handle).
 */
class WebhookIntegration implements IntegrationTypeInterface
{
    use ApiConnector;

    public const SIGNATURE_HEADER = 'X-SimpleForm-Signature';

    public const TIMESTAMP_HEADER = 'X-SimpleForm-Timestamp';

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
            SafeUrl::settingUrlRule('url'),
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
     * The SSRF guard, transport-exception trap, and response mapping come from
     * {@see ApiConnector::request()}.
     *
     * @internal
     */
    public function requestWebhook(string $method, string $url, string $body, string $contentType, ?string $secret): IntegrationResult
    {
        $headers = ['Content-Type' => $contentType];
        if ($secret !== null) {
            // Sign the timestamp together with the body and send the timestamp in
            // its own header, so a receiver can reject a replayed request whose
            // timestamp is outside its freshness window (the signature is bound to
            // that timestamp, so it can't be back-dated).
            $timestamp = (string) time();
            $headers[self::TIMESTAMP_HEADER] = $timestamp;
            $headers[self::SIGNATURE_HEADER] = self::signPayload($timestamp, $body, $secret);
        }

        return $this->request($method, $url, [
            'headers' => $headers,
            'body' => $body,
        ]);
    }

    /**
     * HMAC-SHA256 of `<timestamp>.<body>`, formatted `sha256=<hex>`. The timestamp
     * is part of the signed content so a receiver can bind the signature to the
     * `X-SimpleForm-Timestamp` header and reject stale/replayed deliveries.
     */
    public static function signPayload(string $timestamp, string $body, string $secret): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
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
        $byHandle = SubmissionValues::byHandle($submission);

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

        $payload = [
            'formHandle' => $form?->handle,
            'submissionId' => $submission->id !== null ? (int) $submission->id : null,
            'submissionUid' => $submission->uid,
            'siteId' => $submission->siteId !== null ? (int) $submission->siteId : null,
            'dateCreated' => $submission->dateCreated?->format(\DateTimeInterface::ATOM),
            'data' => $values,
        ];

        // Quiz scoring (#241): add a `quiz` object only for quiz submissions, so
        // a plain form's payload shape is unchanged.
        if ($submission->quizScore !== null) {
            $payload['quiz'] = [
                'score' => (int) $submission->quizScore,
                'maxScore' => $submission->quizMaxScore !== null ? (int) $submission->quizMaxScore : null,
                'percentage' => $submission->quizPercentage !== null ? (int) $submission->quizPercentage : null,
                'grade' => $submission->quizGrade,
            ];
        }

        // UTM/referrer auto-capture (#249): add an `attribution` object only when
        // captured, so a plain form's payload shape is unchanged. The stored map
        // already holds just the non-empty keys.
        if ($submission->attribution !== null && $submission->attribution !== []) {
            $payload['attribution'] = $submission->attribution;
        }

        return $payload;
    }
}

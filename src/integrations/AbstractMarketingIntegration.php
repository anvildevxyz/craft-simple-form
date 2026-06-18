<?php

namespace fabianhaef\simpleform\integrations;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\support\SubmissionValues;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared base for email-marketing connectors (Mailchimp, ActiveCampaign) that
 * subscribe a submitter to an audience/list via an authenticated API. Owns the
 * HTTP-client seam, response→result mapping, email resolution, and field mapping.
 */
abstract class AbstractMarketingIntegration implements IntegrationTypeInterface
{
    abstract public static function handle(): string;

    abstract public static function displayName(): string;

    abstract public function settingsHtml(array $settings): string;

    abstract public function send(Submission $submission, array $settings): IntegrationResult;

    public function defineSettingsRules(): array
    {
        return [
            [['apiKey'], 'required'],
            [['apiKey'], 'string'],
        ];
    }

    /**
     * Resolve the submitter's email: the configured `emailField` handle if it
     * holds a valid address, otherwise the first valid email among the values.
     *
     * @param array<string, mixed> $settings
     */
    protected function resolveEmail(Submission $submission, array $settings): ?string
    {
        $values = SubmissionValues::byHandle($submission);

        $handle = trim((string) ($settings['emailField'] ?? ''));
        if ($handle !== '' && isset($values[$handle]) && is_string($values[$handle])
            && filter_var($values[$handle], FILTER_VALIDATE_EMAIL)) {
            return $values[$handle];
        }

        foreach ($values as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Build a `targetKey => value` map from a configured `handle => targetKey`
     * mapping setting.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    protected function mappedFields(Submission $submission, array $settings, string $mappingKey): array
    {
        $mapping = $settings[$mappingKey] ?? [];
        if (!is_array($mapping) || $mapping === []) {
            return [];
        }

        $values = SubmissionValues::byHandle($submission);
        $out = [];
        foreach ($mapping as $handle => $target) {
            if (is_string($target) && $target !== '' && isset($values[$handle])) {
                $out[$target] = $values[$handle];
            }
        }
        return $out;
    }

    protected function resultFromResponse(ResponseInterface $response): IntegrationResult
    {
        $code = $response->getStatusCode();
        if ($code >= 200 && $code < 300) {
            return IntegrationResult::success($code, 'OK');
        }
        return IntegrationResult::failure($code, substr((string) $response->getBody(), 0, 500));
    }

    protected function httpClient(): Client
    {
        return Craft::createGuzzleClient([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
    }
}

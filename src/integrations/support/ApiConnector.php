<?php

namespace fabianhaef\simpleform\integrations\support;

use Craft;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\integrations\IntegrationResult;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared HTTP/auth plumbing for API-backed connectors (email-marketing, CRM):
 * the client seam, email resolution, field mapping, and response→result. Kept as
 * a trait so connector base classes can compose it without an inheritance chain.
 */
trait ApiConnector
{
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
            // Don't follow redirects (F3): prevents a public API URL from
            // 30x-bouncing the request (and its auth header) to an internal host.
            'allow_redirects' => false,
        ]);
    }
}

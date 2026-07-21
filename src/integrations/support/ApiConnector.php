<?php

namespace anvildev\simpleform\integrations\support;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\helpers\SafeUrl;
use anvildev\simpleform\integrations\IntegrationResult;
use Craft;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared HTTP/auth plumbing for API-backed connectors (email-marketing, CRM):
 * the client seam, email resolution, field mapping, and response→result. Kept as
 * a trait so connector base classes can compose it without an inheritance chain.
 */
trait ApiConnector
{
    /** Reused across a dispatch — the client is built from constant options. */
    private ?Client $_httpClient = null;

    /**
     * Memoized {@see submissionValues()} result.
     *
     * @var array<string, mixed>|null
     */
    private ?array $_valuesCache = null;

    /** The `spl_object_id()` of the submission {@see $_valuesCache} was built for. */
    private ?int $_valuesCacheKey = null;

    /**
     * Resolve the submitter's email: the configured `emailField` handle if it
     * holds a valid address, otherwise the first valid email among the values.
     *
     * @param array<string, mixed> $settings
     */
    protected function resolveEmail(Submission $submission, array $settings): ?string
    {
        $values = $this->submissionValues($submission);

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

        $values = $this->submissionValues($submission);
        $out = [];
        foreach ($mapping as $handle => $target) {
            if (is_string($target) && $target !== '' && isset($values[$handle])) {
                $out[$target] = $values[$handle];
            }
        }
        return $out;
    }

    /**
     * Dispatch a single HTTP request through the SSRF guard and map the outcome
     * to an {@see IntegrationResult}. This is the one place the security-relevant
     * policy lives: the public-address check (F3), the redirect/timeout policy
     * (via {@see httpClient()}), the transport-exception trap, and the
     * 2xx/non-2xx → result mapping. Connectors build the per-provider `$options`
     * (auth + body + headers) and delegate here.
     *
     * @param array<string, mixed> $options Guzzle request options; `http_errors`
     *                                       is always forced off so non-2xx
     *                                       responses map to a failure result
     *                                       rather than throwing.
     */
    protected function request(string $method, string $url, array $options = []): IntegrationResult
    {
        $response = $this->rawRequest($method, $url, $options);
        if ($response instanceof IntegrationResult) {
            return $response;
        }
        return $this->resultFromResponse($response);
    }

    /**
     * Like {@see request()} but returns the raw response so a connector that
     * needs the response body (e.g. to chain a follow-up call) can read it. The
     * SSRF guard and transport-exception trap still apply; on a blocked URL or
     * transport failure a failure {@see IntegrationResult} is returned instead of
     * a response.
     *
     * @param array<string, mixed> $options
     * @return ResponseInterface|IntegrationResult The response on a completed
     *                                             request, or a failure result
     *                                             when blocked or unreachable.
     */
    protected function rawRequest(string $method, string $url, array $options = []): ResponseInterface|IntegrationResult
    {
        // SSRF guard (F3): validate the host and pin its resolved IPs in ONE
        // lookup, so the address that was range-checked is exactly the address the
        // socket connects to (no DNS-rebinding window between check and connect).
        // null = not a public http(s) URL.
        $pinOptions = SafeUrl::guardedRequestOptions($url);
        if ($pinOptions === null) {
            return IntegrationResult::failure(null, 'Blocked request to a non-public address');
        }

        try {
            return $this->httpClient()->request($method, $url, ['http_errors' => false] + $pinOptions + $options);
        } catch (\Throwable $e) {
            return IntegrationResult::failure(null, $e->getMessage());
        }
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
        if ($this->_httpClient !== null) {
            return $this->_httpClient;
        }

        $config = [
            'timeout' => 10,
            'connect_timeout' => 5,
            // Don't follow redirects (F3): prevents a public API URL from
            // 30x-bouncing the request (and its auth header) to an internal host.
            'allow_redirects' => false,
        ];

        // Pin dispatch to the cURL handler so the DNS-rebinding guard's
        // CURLOPT_RESOLVE ({@see SafeUrl::guzzlePinDnsOptions()}) is actually
        // honored: under Guzzle's stream handler that option is silently ignored,
        // reopening the rebinding window between the SSRF check and connect.
        // cURL is effectively always present in a Craft runtime; when it isn't,
        // the pin can't be enforced and only the (still-applied) public-URL check
        // guards the request.
        if (extension_loaded('curl') && class_exists(CurlHandler::class)) {
            $config['handler'] = HandlerStack::create(new CurlHandler());
        }

        return $this->_httpClient = Craft::createGuzzleClient($config);
    }

    /**
     * Memoized {@see SubmissionValues::byHandle()} for the current submission: a
     * single connector dispatch (`send()`) can call both {@see resolveEmail()}
     * and {@see mappedFields()}, and this avoids walking the submission's field
     * values twice. Keyed on the submission's object id so a different
     * submission always recomputes.
     *
     * @return array<string, mixed>
     */
    private function submissionValues(Submission $submission): array
    {
        $key = spl_object_id($submission);
        if ($this->_valuesCacheKey !== $key) {
            $this->_valuesCache = SubmissionValues::byHandle($submission);
            $this->_valuesCacheKey = $key;
        }
        return $this->_valuesCache;
    }
}

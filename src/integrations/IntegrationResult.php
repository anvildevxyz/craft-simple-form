<?php

namespace fabianhaef\simpleform\integrations;

/**
 * Immutable outcome of a single integration dispatch attempt. Connectors return
 * one of these from {@see IntegrationTypeInterface::send()}; the dispatch job
 * records it on the integration-log row.
 */
final class IntegrationResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $responseCode,
        public readonly string $message,
    ) {
    }

    public static function success(?int $responseCode = null, string $message = ''): self
    {
        return new self(true, $responseCode, $message);
    }

    public static function failure(?int $responseCode = null, string $message = ''): self
    {
        return new self(false, $responseCode, $message);
    }
}

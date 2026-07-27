<?php

namespace anvildev\simpleform\integrations;

/**
 * Immutable outcome of a single integration dispatch attempt. Connectors return
 * one of these from {@see IntegrationTypeInterface::send()}; the dispatch job
 * records it on the integration-log row.
 *
 * Connectors that create a local Craft element (see
 * {@see CraftElementIntegration}) attach the created element's id + type via
 * {@see withElement()} so the dispatch log can deep-link to it and a resend can
 * detect the existing element.
 */
final class IntegrationResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?int $responseCode,
        public readonly string $message,
        public readonly ?int $elementId = null,
        public readonly ?string $elementType = null,
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

    /**
     * Return a copy carrying the created element's id + fully-qualified class so
     * the dispatch log can link back to it.
     */
    public function withElement(int $elementId, string $elementType): self
    {
        return new self($this->success, $this->responseCode, $this->message, $elementId, $elementType);
    }
}

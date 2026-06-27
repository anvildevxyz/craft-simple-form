<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\services\IntegrationsService;
use PHPUnit\Framework\TestCase;

/**
 * Pure tests for {@see IntegrationsService::redactSecrets()} — the single source
 * of truth the portability export (#139) uses to keep third-party credentials out
 * of an exported form definition.
 */
class SecretRedactionTest extends TestCase
{
    /**
     * Every secret key listed by the service is replaced with the redaction
     * placeholder, regardless of its original (non-empty) value.
     */
    public function testEverySecretKeyIsRedacted(): void
    {
        $settings = [];
        foreach (IntegrationsService::SECRET_KEYS as $key) {
            $settings[$key] = 'super-secret-' . $key;
        }

        $redacted = IntegrationsService::redactSecrets($settings);

        foreach (IntegrationsService::SECRET_KEYS as $key) {
            $this->assertSame(IntegrationsService::REDACTED, $redacted[$key]);
        }
    }

    public function testNonSecretKeysSurviveUntouched(): void
    {
        $redacted = IntegrationsService::redactSecrets([
            'url' => 'https://example.test/hook',
            'method' => 'POST',
            'apiKey' => 'abc123',
        ]);

        $this->assertSame('https://example.test/hook', $redacted['url']);
        $this->assertSame('POST', $redacted['method']);
        $this->assertSame(IntegrationsService::REDACTED, $redacted['apiKey']);
    }

    /**
     * A present-but-empty secret is still redacted, so its shape never leaks.
     */
    public function testEmptySecretIsStillRedacted(): void
    {
        $redacted = IntegrationsService::redactSecrets(['token' => '']);
        $this->assertSame(IntegrationsService::REDACTED, $redacted['token']);
    }

    public function testAbsentSecretKeyIsNotAdded(): void
    {
        $redacted = IntegrationsService::redactSecrets(['url' => 'https://example.test']);
        $this->assertArrayNotHasKey('apiKey', $redacted);
    }
}

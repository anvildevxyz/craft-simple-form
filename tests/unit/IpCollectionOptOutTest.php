<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * #293 — GDPR data minimization: a `collectIpAddresses` opt-out that stops
 * storing the visitor IP on submissions. Default stays on (existing behavior);
 * the transient rate limiter is deliberately unaffected.
 */
class IpCollectionOptOutTest extends TestCase
{
    public function testDefaultsToCollecting(): void
    {
        $this->assertTrue((new Settings())->collectIpAddresses);
    }

    public function testSettingIsBooleanValidated(): void
    {
        $settings = new Settings();
        $rules = $settings->rules();

        $found = false;
        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }
            if (($rule[1] ?? null) === 'boolean' && in_array('collectIpAddresses', (array) $rule[0], true)) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'collectIpAddresses needs a boolean rule');
    }

    public function testCaptureIsGatedAndEditableOnThePrivacyTab(): void
    {
        $service = (string) file_get_contents(__DIR__ . '/../../src/services/SubmissionService.php');
        // The gate sits inside sourceIp() so every storage path is covered.
        $this->assertMatchesRegularExpression(
            '/function sourceIp\(\): \?string\s*\{[^}]*collectIpAddresses/s',
            $service,
        );

        $controller = (string) file_get_contents(__DIR__ . '/../../src/controllers/SettingsController.php');
        $this->assertMatchesRegularExpression("/'privacy' => \['collectIpAddresses',/", $controller);
        $this->assertStringContainsString("'collectIpAddresses'];", $controller);

        $tab = (string) file_get_contents(__DIR__ . '/../../src/templates/settings/_tabs/privacy.twig');
        $this->assertStringContainsString("name: 'collectIpAddresses'", $tab);
    }
}

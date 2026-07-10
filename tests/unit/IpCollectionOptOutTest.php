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

    public function testPolicyDefaultsToFullFromLegacyOn(): void
    {
        $this->assertSame(Settings::IP_CAPTURE_FULL, (new Settings())->ipCapturePolicy);
    }

    public function testPolicyDefaultsToOffFromLegacyOff(): void
    {
        $settings = new Settings(['collectIpAddresses' => false]);
        $this->assertSame(Settings::IP_CAPTURE_OFF, $settings->ipCapturePolicy);
    }

    public function testExplicitPolicyKeepsLegacyBooleanInLockstep(): void
    {
        $anonymized = new Settings(['ipCapturePolicy' => Settings::IP_CAPTURE_ANONYMIZED]);
        $this->assertTrue($anonymized->collectIpAddresses);

        $off = new Settings(['ipCapturePolicy' => Settings::IP_CAPTURE_OFF]);
        $this->assertFalse($off->collectIpAddresses);
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
        // #315 supersedes the boolean with a three-state policy; the gate now
        // lives on ipCapturePolicy but collectIpAddresses stays in lockstep.
        $service = (string) file_get_contents(__DIR__ . '/../../src/services/SubmissionService.php');
        // The gate sits inside sourceIp() so every storage path is covered.
        $this->assertMatchesRegularExpression(
            '/function sourceIp\(\): \?string\s*\{[^}]*ipCapturePolicy/s',
            $service,
        );

        $controller = (string) file_get_contents(__DIR__ . '/../../src/controllers/SettingsController.php');
        $this->assertMatchesRegularExpression("/'privacy' => \['ipCapturePolicy',/", $controller);
        $this->assertStringContainsString('$values[\'collectIpAddresses\'] = ', $controller);

        $tab = (string) file_get_contents(__DIR__ . '/../../src/templates/settings/_tabs/privacy.twig');
        $this->assertStringContainsString("name: 'ipCapturePolicy'", $tab);
    }
}

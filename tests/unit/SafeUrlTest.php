<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\helpers\SafeUrl;
use PHPUnit\Framework\TestCase;

/**
 * SSRF guard (audit finding F3, CWE-918). Uses IP-literal and localhost hosts so
 * the checks are deterministic without external DNS.
 */
class SafeUrlTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            'aws imds link-local' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv4 loopback' => ['http://127.0.0.1:6379/'],
            'private 10/8' => ['http://10.0.0.5/hook'],
            'private 192.168' => ['https://192.168.1.10/'],
            'private 172.16' => ['http://172.16.0.1/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'localhost name' => ['http://localhost/'],
            'non-http scheme ftp' => ['ftp://8.8.8.8/'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://8.8.8.8/'],
            'no scheme' => ['8.8.8.8/path'],
            'garbage' => ['not a url'],
            'empty' => [''],
        ];
    }

    /**
     * @dataProvider blockedUrls
     */
    public function testBlockedUrlsAreRejected(string $url): void
    {
        $this->assertFalse(SafeUrl::isPublicHttpUrl($url), "$url should be blocked");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function allowedUrls(): array
    {
        return [
            'public ipv4 http' => ['http://8.8.8.8/hook'],
            'public ipv4 https' => ['https://1.1.1.1/api/3/contact/sync'],
            'public ipv4 with port' => ['https://8.8.4.4:8443/'],
        ];
    }

    /**
     * @dataProvider allowedUrls
     */
    public function testPublicUrlsAreAllowed(string $url): void
    {
        $this->assertTrue(SafeUrl::isPublicHttpUrl($url), "$url should be allowed");
    }

    public function testAssertThrowsOnBlockedUrl(): void
    {
        $this->expectException(\RuntimeException::class);
        SafeUrl::assertPublicHttpUrl('http://169.254.169.254/');
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function blockedRedirectUrls(): array
    {
        return [
            'javascript' => ['javascript:alert(1)', 'example.com'],
            'protocol-relative' => ['//evil.example/phish', 'example.com'],
            'off-site absolute' => ['https://evil.example/thanks', 'example.com'],
            'empty' => ['', 'example.com'],
        ];
    }

    /**
     * @dataProvider blockedRedirectUrls
     */
    public function testBlockedRedirectUrlsAreRejected(string $url, ?string $siteHost): void
    {
        $this->assertFalse(SafeUrl::isSafeRedirectUrl($url, $siteHost));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function allowedRedirectUrls(): array
    {
        return [
            'relative path' => ['/thanks?x=1', 'example.com'],
            'same-site absolute' => ['https://example.com/thanks', 'example.com'],
        ];
    }

    /**
     * @dataProvider allowedRedirectUrls
     */
    public function testAllowedRedirectUrls(string $url, ?string $siteHost): void
    {
        $this->assertTrue(SafeUrl::isSafeRedirectUrl($url, $siteHost));
    }

    public function testAcceptableRedirectTemplateRejectsProtocolRelative(): void
    {
        $this->assertFalse(SafeUrl::isAcceptableRedirectTemplate('//evil.example'));
    }

    public function testAcceptableRedirectTemplateAllowsRelativeWithPlaceholder(): void
    {
        $this->assertTrue(SafeUrl::isAcceptableRedirectTemplate('/thanks?e={email}'));
    }

    public function testSettingUrlRuleTargetsItsAttribute(): void
    {
        [$attributes, $validator] = SafeUrl::settingUrlRule('apiUrl');
        $this->assertSame(['apiUrl'], $attributes);
        $this->assertInstanceOf(\Closure::class, $validator);
    }

    /**
     * The rule's closure must NOT flag a value that {@see
     * SafeUrl::isAcceptableSettingUrl} accepts. The closure is invoked exactly as
     * Yii's InlineValidator does — `bindTo($model)` then call — against a stub
     * model that records `addError`. The acceptance branch never reaches
     * `Craft::t`, so no Craft bootstrap is needed here; the rejection/translation
     * path is covered end-to-end in the integration suite.
     *
     * @dataProvider acceptableSettingUrls
     */
    public function testSettingUrlRuleAcceptsValidUrl(string $value): void
    {
        $errors = $this->runSettingRule($value);
        $this->assertSame([], $errors, "$value should not be flagged");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptableSettingUrls(): array
    {
        // Env-ref acceptance ($VAR) routes through App::env and is covered in the
        // integration suite; these two cases stay free of a Craft bootstrap.
        return [
            'public ipv4 https' => ['https://8.8.8.8/hook'],
            'empty (deferred to required rule)' => [''],
        ];
    }

    /**
     * Invoke the rule's closure the way {@see \yii\validators\InlineValidator}
     * does: bind `$this` to the validated model, then call it with the inline
     * validator signature. Returns the attributes the closure flagged.
     *
     * @return list<string>
     */
    private function runSettingRule(string $value): array
    {
        [, $validator] = SafeUrl::settingUrlRule('url');

        $model = new class {
            /** @var list<string> */
            public array $flagged = [];

            public function addError(string $attribute, string $message): void
            {
                $this->flagged[] = $attribute;
            }
        };

        \Closure::bind($validator, $model)('url', null, null, $value);

        return $model->flagged;
    }
}

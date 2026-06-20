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
}

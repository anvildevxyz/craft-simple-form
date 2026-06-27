<?php

namespace anvildev\simpleform\helpers;

use Craft;
use craft\helpers\App;

/**
 * SSRF guard for outbound integration requests (audit finding F3, CWE-918).
 *
 * Integration target URLs are configured by CP users holding only
 * `manageIntegrations` (a non-admin permission). Without a guard, such a user
 * can point a webhook/CRM URL at internal infrastructure — cloud metadata
 * (169.254.169.254), localhost services, RFC-1918 hosts — and the server will
 * dispatch submission data there and reflect up to 500 bytes of the response
 * into the integration log. This helper rejects any URL whose host resolves to
 * a private, loopback, link-local or otherwise reserved address, and restricts
 * the scheme to http(s).
 *
 * Residual risk: a hostname can pass this check and then re-resolve to a private
 * address before the socket connects (DNS rebinding). Callers therefore also
 * disable HTTP redirect-following; pinning the resolved IP at the transport
 * layer would be required to fully close the rebinding gap.
 */
final class SafeUrl
{
    /**
     * True when $url is safe to dispatch to: an absolute http(s) URL whose host
     * does not resolve to any private or reserved address.
     *
     * A host that cannot be resolved is permitted — it is not an SSRF target
     * (the socket simply fails to connect) and blocking it would needlessly
     * break a legitimately-public host during a transient DNS failure. Every
     * real internal target (IP literals, localhost, internal names backed by
     * private DNS) *does* resolve, to a private/reserved IP, and is rejected.
     */
    public static function isPublicHttpUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !self::isHttp($parts['scheme'])) {
            return false;
        }

        foreach (self::resolveIps(trim($parts['host'], '[]')) as $ip) {
            if (!self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Save-time acceptance check for an env-aware URL setting. Empty values and
     * unresolved environment references ($VAR) are allowed through — emptiness
     * is covered by the connector's own `required` rule, and an env reference
     * can only be resolved (and is re-checked) at request time. A concrete URL
     * is rejected here when it is not a public http(s) URL, giving the operator
     * immediate feedback in the Control Panel.
     */
    public static function isAcceptableSettingUrl(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        $resolved = App::parseEnv($value);
        if (is_string($resolved) && str_starts_with($resolved, '$')) {
            // Unresolved env reference — defer to the request-time guard.
            return true;
        }

        // Same rule as the request-time guard: reject a bad scheme or a host
        // that positively resolves to a private/reserved address; a host that
        // doesn't resolve at save time is deferred to the request-time guard.
        return self::isPublicHttpUrl((string) $resolved);
    }

    /**
     * Yii inline validation rule that rejects a setting URL which is a concrete,
     * non-public http(s) address. Connectors reuse this for their own URL
     * setting so the {@see isAcceptableSettingUrl} check and its translated error
     * message live in one place.
     *
     * The returned closure relies on Yii's {@see \yii\validators\InlineValidator}
     * re-binding `$this` to the validated model, so `$this->addError(...)`
     * resolves against that model exactly as an inline closure declared in
     * `defineSettingsRules()` would.
     *
     * @return array{0: list<string>, 1: \Closure}
     */
    public static function settingUrlRule(string $attribute): array
    {
        return [
            [$attribute],
            function($attr, $params, $validator, $value): void {
                if (is_string($value) && !SafeUrl::isAcceptableSettingUrl($value)) {
                    // $this is rebound to the validated model by Yii's
                    // InlineValidator (Closure::bindTo); see settingUrlRule() doc.
                    $this->addError($attr, Craft::t('simple-form', 'The URL must be a public http(s) address.'));
                }
            },
        ];
    }

    /**
     * @throws \RuntimeException when the URL is not a public http(s) URL.
     */
    public static function assertPublicHttpUrl(string $url): void
    {
        if (!self::isPublicHttpUrl($url)) {
            throw new \RuntimeException('Blocked request to a non-public or invalid URL (SSRF guard).');
        }
    }

    /**
     * True when $url is safe to hand to the browser as a post-submit redirect:
     * a same-site relative path (`/…`, not `//…`) or an absolute http(s) URL on
     * $siteHost. Rejects `javascript:`, `data:`, protocol-relative, and off-site
     * absolute URLs (CWE-601).
     */
    public static function isSafeRedirectUrl(string $url, ?string $siteHost = null): bool
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^(javascript|data|vbscript):#i', $url) || str_starts_with($url, '//')) {
            return false;
        }

        if (str_starts_with($url, '/')) {
            return true;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) || !self::isHttp($parts['scheme'])) {
            return false;
        }

        if ($siteHost === null || $siteHost === '') {
            return false;
        }

        return strtolower($parts['host']) === strtolower($siteHost);
    }

    /**
     * Save-time check for a redirect URL *template* (may contain `{handle}`
     * placeholders). Rejects dangerous schemes and protocol-relative paths; allows
     * site-relative paths and absolute http(s) templates (host checked after
     * interpolation at submit time).
     */
    public static function isAcceptableRedirectTemplate(string $template): bool
    {
        $template = trim($template);
        if ($template === '' || preg_match('#^(javascript|data|vbscript):#i', $template) || str_starts_with($template, '//')) {
            return false;
        }

        if (str_starts_with($template, '/')) {
            return true;
        }

        $parts = parse_url(preg_replace('/\{[a-zA-Z_][a-zA-Z0-9_]*\}/', 'x', $template) ?? $template);

        return $parts !== false && isset($parts['scheme'], $parts['host']) && self::isHttp($parts['scheme']);
    }

    /**
     * Resolve a hostname to its IP addresses for DNS pinning at request time.
     *
     * @return list<string>
     */
    public static function resolveHostIps(string $host): array
    {
        return self::resolveIps(trim($host, '[]'));
    }

    /**
     * Guzzle/cURL options that pin $url's host to the IPs resolved at call time,
     * closing the DNS-rebinding window between {@see isPublicHttpUrl()} and connect.
     *
     * @return array<string, mixed>
     */
    public static function guzzlePinDnsOptions(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return [];
        }

        $host = trim($parts['host'], '[]');
        $ips = self::resolveHostIps($host);
        if ($ips === []) {
            return [];
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return ['curl' => [CURLOPT_RESOLVE => array_map(static fn(string $ip): string => "{$host}:{$port}:{$ip}", $ips)]];
    }

    /**
     * Resolve a host (or IP literal) to the list of IP addresses it points at.
     * IP literals resolve to themselves; hostnames are resolved for both A and
     * (best-effort) AAAA records.
     *
     * @return list<string>
     */
    private static function resolveIps(string $host): array
    {
        if ($host === '') {
            return [];
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = gethostbynamel($host) ?: [];

        // Best-effort IPv6 lookup; ignored if DNS is unavailable.
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }

    /** True when $scheme is http or https (case-insensitive). */
    private static function isHttp(string $scheme): bool
    {
        return in_array(strtolower($scheme), ['http', 'https'], true);
    }

    /**
     * True when the IP is neither private (RFC-1918 / ULA) nor reserved
     * (loopback, link-local, 0.0.0.0/8, etc.).
     */
    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}

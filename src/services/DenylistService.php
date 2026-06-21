<?php

namespace fabianhaef\simpleform\services;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\fields\EmailFieldType;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Deterministic, owner-controlled spam denylists (#140): blocked keywords,
 * emails/domains, and IPs/CIDR ranges. These run before Akismet and need no
 * third-party API call. A match returns a specific reason string (e.g.
 * `keyword:casino`, `email:bob@x.tld`, `ip:203.0.113.5`) that becomes the
 * submission's `spamReason`, so the CP quarantine queue can show *why*.
 *
 * The lists live as newline-separated text blobs in {@see \fabianhaef\simpleform\models\Settings};
 * this service parses/normalises them and performs the matching.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class DenylistService extends Component
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Find the first denylist hit for a submission, or null if it is clean.
     *
     * Checks the blocked-IP list against the submitter's IP, then the
     * blocked-email list and the blocked-keyword list against the submitted
     * values. Returns a specific reason string on the first match.
     *
     * @param array<string, mixed> $data the built submission data (field_<id> => {label,type,value})
     */
    public function match(Form $form, array $data): ?string
    {
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->enableDenylists) {
            return null;
        }

        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $ip = $request->getIsConsoleRequest() ? null : $request->getUserIP();

        if ($ip !== null && $ip !== '' && $this->matchIp($settings->blockedIps, $ip)) {
            return 'ip:' . $ip;
        }

        $emailHit = $this->matchEmails($settings->blockedEmails, $data);
        if ($emailHit !== null) {
            return 'email:' . $emailHit;
        }

        $keywordHit = $this->matchKeywords($settings->blockedKeywords, $data);
        if ($keywordHit !== null) {
            return 'keyword:' . $keywordHit;
        }

        return null;
    }

    /**
     * Whether a single denylist line is a valid IPv4/IPv6 address or CIDR range.
     * Shared with {@see \fabianhaef\simpleform\models\Settings::validateBlockedIps()}
     * so the save-time validation and the runtime matcher agree on what is parseable.
     */
    public static function isValidIpEntry(string $entry): bool
    {
        $entry = trim($entry);
        if ($entry === '') {
            return false;
        }

        if (!str_contains($entry, '/')) {
            return filter_var($entry, FILTER_VALIDATE_IP) !== false;
        }

        [$subnet, $bits] = explode('/', $entry, 2);
        if (filter_var($subnet, FILTER_VALIDATE_IP) === false || !ctype_digit($bits)) {
            return false;
        }

        $max = filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? 128 : 32;
        return (int) $bits >= 0 && (int) $bits <= $max;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * Match a submitter IP against the blocked-IP list (single IPs + CIDR ranges).
     */
    private function matchIp(?string $list, string $ip): bool
    {
        foreach ($this->lines($list) as $entry) {
            if (!str_contains($entry, '/')) {
                if (strcasecmp($entry, $ip) === 0) {
                    return true;
                }
                continue;
            }

            if ($this->ipInCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match the submitted email values against the blocked-email list. Each list
     * entry is an exact address, an `@domain.tld`, or a `*.domain.tld` wildcard.
     * Returns the matched submitted email, or null.
     *
     * @param array<string, mixed> $data
     */
    private function matchEmails(?string $list, array $data): ?string
    {
        $entries = $this->lines($list);
        if ($entries === []) {
            return null;
        }

        foreach ($this->extractEmails($data) as $email) {
            $lower = strtolower($email);
            $domain = substr($lower, strpos($lower, '@') === false ? 0 : strpos($lower, '@') + 1);

            foreach ($entries as $entry) {
                $entry = strtolower($entry);

                // Exact address.
                if (str_contains($entry, '@') && !str_starts_with($entry, '@') && $entry === $lower) {
                    return $email;
                }

                // '@domain.tld' — whole-domain block.
                if (str_starts_with($entry, '@') && '@' . $domain === $entry) {
                    return $email;
                }

                // '*.domain.tld' — domain and any subdomain.
                if (str_starts_with($entry, '*.')) {
                    $base = substr($entry, 2);
                    if ($domain === $base || str_ends_with($domain, '.' . $base)) {
                        return $email;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Match the submitted text values against the blocked-keyword list. Each
     * entry matches case-insensitively as a substring; a `*` is a wildcard.
     * Returns the matched keyword entry (trimmed of wildcards), or null.
     *
     * @param array<string, mixed> $data
     */
    private function matchKeywords(?string $list, array $data): ?string
    {
        $entries = $this->lines($list);
        if ($entries === []) {
            return null;
        }

        $haystack = strtolower($this->extractText($data));
        if ($haystack === '') {
            return null;
        }

        foreach ($entries as $entry) {
            $needle = strtolower($entry);

            if (str_contains($needle, '*')) {
                $pattern = '/' . str_replace('\*', '.*', preg_quote($needle, '/')) . '/u';
                if (preg_match($pattern, $haystack) === 1) {
                    return trim($entry, '*');
                }
                continue;
            }

            if (str_contains($haystack, $needle)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Whether an IP is inside a CIDR range. Supports IPv4 and IPv6.
     */
    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        // Compare the leading $bits of the binary representations.
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $remainder)) - 1) & 0xFF;
        return (ord($ipBin[$bytes]) & $mask) === (ord($subnetBin[$bytes]) & $mask);
    }

    /**
     * Concatenate every submitted text value into one searchable blob.
     *
     * @param array<string, mixed> $data
     */
    private function extractText(array $data): string
    {
        $parts = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['value'] ?? '';
            if (is_array($value)) {
                $value = implode(' ', array_map('strval', $value));
            }
            if ((string) $value !== '') {
                $parts[] = (string) $value;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Every submitted value that looks like an email address (field type `email`,
     * or any scalar value matching an email pattern).
     *
     * @param array<string, mixed> $data
     * @return list<string>
     */
    private function extractEmails(array $data): array
    {
        $emails = [];
        foreach ($data as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $value = $entry['value'] ?? null;
            if (is_array($value) || $value === null || $value === '') {
                continue;
            }

            $value = (string) $value;
            if (($entry['type'] ?? '') === EmailFieldType::getType() || filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                $emails[] = $value;
            }
        }

        return array_values(array_unique($emails));
    }

    /**
     * Split a newline-separated text blob into trimmed, non-empty lines.
     *
     * @return list<string>
     */
    private function lines(?string $list): array
    {
        if ($list === null || trim($list) === '') {
            return [];
        }

        $lines = [];
        foreach (preg_split('/\R/', $list) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines;
    }
}

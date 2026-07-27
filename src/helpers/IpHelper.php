<?php

namespace anvildev\simpleform\helpers;

/**
 * IP-address anonymization for the "anonymized" capture policy (#315).
 *
 * Masks an IP so it retains coarse geo/rate signal without identifying an
 * individual host: the last octet of an IPv4 address is zeroed (keeping the
 * top 24 bits) and the low 80 bits of an IPv6 address are zeroed (keeping the
 * top 48 bits). This mirrors the common GDPR-friendly masking used by analytics
 * platforms. Masking is applied at capture time so a full IP is never written
 * to the database in anonymized mode.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
final class IpHelper
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * Return $ip with its host-identifying bits zeroed: the last octet for IPv4
     * (e.g. `203.0.113.42` → `203.0.113.0`) and the low 80 bits for IPv6
     * (e.g. `2001:db8:1:2:3:4:5:6` → `2001:db8:1::`). A value that is not a
     * valid IP is returned unchanged.
     */
    public static function anonymize(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return $ip;
        }

        $length = strlen($packed);
        $mask = match ($length) {
            4 => "\xff\xff\xff\x00",
            16 => str_repeat("\xff", 6) . str_repeat("\x00", 10),
            default => null,
        };
        if ($mask === null) {
            return $ip;
        }

        $masked = @inet_ntop($packed & $mask);

        return $masked === false ? $ip : $masked;
    }

    /**
     * Whether $entry is a valid single IP or CIDR range (e.g. `203.0.113.5`,
     * `203.0.113.0/24`, `2001:db8::/32`), used to validate the denylist's
     * blocked-IP entries. Rejects a prefix length wider than the address family
     * allows (32 for IPv4, 128 for IPv6).
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

        return (int) $bits <= $max;
    }
}

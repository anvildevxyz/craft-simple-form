<?php

namespace fabianhaef\simpleform\helpers;

use Craft;

/**
 * Shared fixed-window-by-TTL counter for per-actor abuse throttling, backed by
 * Craft's cache. Used by the public submit endpoints (per visitor IP) and the
 * MCP auth path (per IP), so the two don't drift.
 *
 * Best-effort only: the read-then-write is not atomic, so a burst of truly
 * simultaneous requests can over-admit slightly. That is acceptable for an
 * abuse throttle — it bounds sustained volume, not exact per-request ordering.
 * It also relies on a working cache; with a null/dummy cache backend the
 * counter never accumulates and the limit is effectively disabled.
 */
final class RateLimiter
{
    private const PREFIX = 'simple-form:ratelimit:';

    /**
     * Whether $key has already reached $max hits in the current window.
     */
    public static function isLimited(string $key, int $max): bool
    {
        if ($max <= 0) {
            return false;
        }

        return (int) Craft::$app->getCache()->get(self::PREFIX . $key) >= $max;
    }

    /**
     * Record one hit for $key. The window restarts from this hit (TTL is
     * refreshed), so a sustained stream of requests stays counted until it
     * pauses for $windowSeconds.
     */
    public static function hit(string $key, int $windowSeconds): void
    {
        $cache = Craft::$app->getCache();
        $cacheKey = self::PREFIX . $key;
        $cache->set($cacheKey, (int) $cache->get($cacheKey) + 1, $windowSeconds);
    }
}

<?php

namespace anvildev\simpleform\helpers;

use Craft;
use yii\caching\DummyCache;

/**
 * Shared fixed-window-by-TTL counter for per-actor abuse throttling, backed by
 * Craft's cache. Used by the public submit endpoints (per visitor IP) and the
 * MCP auth path (per IP), so the two don't drift.
 *
 * Best-effort only: the per-hit increment is a read-then-write, so a burst of
 * truly simultaneous requests can over-admit slightly. That is acceptable for
 * an abuse throttle — it bounds sustained volume, not exact per-request
 * ordering. The window-creation race is closed with an atomic {@see add()}.
 *
 * The throttle relies on a working cache: with a `DummyCache` backend the
 * counter never accumulates and the limit is effectively off. That is no longer
 * silent — {@see isLimited()} logs a warning so the disabled state is visible
 * rather than a control that vanished without signal (CWE-703).
 */
final class RateLimiter
{
    private const PREFIX = 'simple-form:ratelimit:';

    private static bool $warnedIneffective = false;

    /**
     * Whether $key has already reached $max hits in the current window.
     */
    public static function isLimited(string $key, int $max): bool
    {
        if ($max <= 0) {
            return false;
        }

        self::warnIfIneffective();

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

        // Initialize the window atomically: add() writes only when the key is
        // absent, so two concurrent first-requests can't both create it and
        // reset the count to 1. Once it exists, bump and refresh the TTL. The
        // increment is still a read-then-write — Yii's portable cache API has no
        // atomic increment for the file/dummy backends — which can over-admit
        // slightly under a simultaneous burst, acceptable per the class doc.
        if (!$cache->add($cacheKey, 1, $windowSeconds)) {
            $cache->set($cacheKey, (int) $cache->get($cacheKey) + 1, $windowSeconds);
        }
    }

    /**
     * Log once when the application cache is a {@see DummyCache}, under which the
     * throttle can't accumulate and is effectively disabled — so operators get a
     * signal instead of a silently-absent abuse control.
     */
    private static function warnIfIneffective(): void
    {
        if (self::$warnedIneffective) {
            return;
        }

        if (Craft::$app->getCache() instanceof DummyCache) {
            self::$warnedIneffective = true;
            Craft::warning(
                'Rate limiting is enabled but the application cache is a DummyCache, so the throttle cannot accumulate and is effectively disabled. Configure a real cache (file, Redis, …) to enforce the submit/MCP rate limits.',
                'simple-form',
            );
        }
    }
}

<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\helpers\App;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;
use yii\caching\TagDependency;

/**
 * Caches the resolved field set (decoded field config + per-site label/help
 * text) for a `(formId, siteId)` pair via Craft's cache component, reusing it on
 * every render until the form changes.
 *
 * Only the *structure* is cached. Per-request, dynamic values (CSRF token,
 * captcha nonce) are NOT part of the field set and are injected at render time.
 *
 * Caching is bypassed entirely when `devMode` is on, when Craft's cache is a
 * dummy/disabled cache, or when the `cacheFormStructure` setting is false, so
 * those environments always reflect current DB state.
 *
 * Invalidation is tag-based: every form's entries (across all sites) share the
 * tag returned by {@see self::tagForForm()}, so a single save/delete can clear
 * every cached site for that form in one call.
 */
class FormStructureService extends Component
{
    private const KEY_PREFIX = 'simple-form:form-structure';
    private const TAG_PREFIX = 'simple-form:form';

    /**
     * Return the resolved field set for a form/site, serving it from cache when
     * caching is enabled, otherwise reading it straight from the DB.
     *
     * @return array<int,array<string,mixed>>
     */
    public function getFieldSet(int $formId, ?int $siteId = null): array
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        if (!$this->cachingEnabled()) {
            return FieldQueryHelper::fieldsForForm($formId, $siteId);
        }

        $cache = Craft::$app->getCache();
        $key = $this->keyFor($formId, $siteId);

        $cached = $cache->get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $fieldSet = FieldQueryHelper::fieldsForForm($formId, $siteId);

        $cache->set($key, $fieldSet, null, new TagDependency([
            'tags' => [$this->tagForForm($formId)],
        ]));

        return $fieldSet;
    }

    /**
     * Batch-resolve field sets for many forms for one site, keyed by formId.
     *
     * Eliminates the N+1 when listing multiple forms: cache hits are served from
     * cache, and every cache miss is resolved in a SINGLE batched DB query via
     * {@see FieldQueryHelper::fieldsForForms()}. Misses are then written back to
     * the cache (tagged per form) so subsequent renders hit the cache.
     *
     * @param int[] $formIds
     * @return array<int,array<int,array<string,mixed>>> formId => field rows
     */
    public function getFieldSets(array $formIds, ?int $siteId = null): array
    {
        $formIds = array_values(array_unique(array_map('intval', $formIds)));
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        if (empty($formIds)) {
            return [];
        }

        if (!$this->cachingEnabled()) {
            return FieldQueryHelper::fieldsForForms($formIds, $siteId);
        }

        $cache = Craft::$app->getCache();
        $result = [];
        $misses = [];

        foreach ($formIds as $formId) {
            $cached = $cache->get($this->keyFor($formId, $siteId));
            if (is_array($cached)) {
                $result[$formId] = $cached;
            } else {
                $misses[] = $formId;
            }
        }

        if (!empty($misses)) {
            // One query for every cache miss, regardless of how many forms missed.
            $fetched = FieldQueryHelper::fieldsForForms($misses, $siteId);
            foreach ($fetched as $formId => $fieldSet) {
                $cache->set($this->keyFor($formId, $siteId), $fieldSet, null, new TagDependency([
                    'tags' => [$this->tagForForm($formId)],
                ]));
                $result[$formId] = $fieldSet;
            }
        }

        return $result;
    }

    /**
     * Invalidate the cached structure for a form across ALL sites.
     *
     * Call this whenever a form, its fields, or their per-site labels/options/
     * config change. Safe to call when caching is disabled (it's a no-op against
     * a dummy cache).
     */
    public function invalidate(int $formId): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), $this->tagForForm($formId));
    }

    /**
     * Warm the cache for a form by resolving its field set for the given sites
     * (defaults to all sites). Returns the number of (formId, siteId) entries
     * warmed. A no-op that still resolves from the DB when caching is disabled.
     *
     * @param int[]|null $siteIds
     */
    public function warm(int $formId, ?array $siteIds = null): int
    {
        $siteIds ??= array_map(
            static fn($site) => (int)$site->id,
            Craft::$app->getSites()->getAllSites()
        );

        $count = 0;
        foreach ($siteIds as $siteId) {
            $this->getFieldSet($formId, (int)$siteId);
            $count++;
        }

        return $count;
    }

    /**
     * Cache key for a single (formId, siteId) field set.
     */
    private function keyFor(int $formId, int $siteId): string
    {
        return sprintf('%s:%d:%d', self::KEY_PREFIX, $formId, $siteId);
    }

    /**
     * Cache tag shared by all of a form's per-site entries, used for one-shot
     * invalidation across sites.
     */
    private function tagForForm(int $formId): string
    {
        return sprintf('%s:%d', self::TAG_PREFIX, $formId);
    }

    /**
     * Whether the structure cache should be used. Off in devMode, off when the
     * setting is disabled, and off when Craft is running with a dummy/disabled
     * cache component (so cache-off environments always read fresh).
     */
    private function cachingEnabled(): bool
    {
        if (App::devMode()) {
            return false;
        }

        if (!Plugin::getInstance()->getSettings()->cacheFormStructure) {
            return false;
        }

        return !(Craft::$app->getCache() instanceof \yii\caching\DummyCache);
    }
}

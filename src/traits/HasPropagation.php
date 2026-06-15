<?php

namespace fabianhaef\simpleform\traits;

use Craft;
use craft\enums\PropagationMethod;
use craft\helpers\ArrayHelper;

/**
 * Shared multi-site propagation support for localized elements.
 *
 * Provides the $propagationMethod property and getSupportedSites() implementation,
 * which together define "where a form should be saved to". The string DB column is
 * coerced to the enum automatically by craft\helpers\Typecast during element population;
 * controllers must cast posted strings with PropagationMethod::tryFrom() before assigning.
 */
trait HasPropagation
{
    public PropagationMethod $propagationMethod = PropagationMethod::None;

    /**
     * @return array<int, int|array<string, mixed>>
     */
    public function getSupportedSites(): array
    {
        $sites = Craft::$app->getSites();
        $currentSite = fn() => $sites->getSiteById($this->siteId) ?? $sites->getPrimarySite();
        return match ($this->propagationMethod) {
            PropagationMethod::All => ArrayHelper::getColumn($sites->getAllSites(), 'id'),
            PropagationMethod::SiteGroup => ArrayHelper::getColumn($sites->getSitesByGroupId($currentSite()->groupId), 'id'),
            PropagationMethod::Language => ArrayHelper::getColumn(
                array_filter($sites->getAllSites(), fn($s) => $s->language === $currentSite()->language),
                'id'
            ),
            default => [$this->siteId ?? Craft::$app->getSites()->getPrimarySite()->id],
        };
    }

    /**
     * Site IDs this element propagates to, normalized from getSupportedSites()
     * (whose entries may be ints or `{siteId}` rows). Callers apply their own
     * fallback when the result is empty.
     *
     * @return list<int>
     */
    public function supportedSiteIds(): array
    {
        $ids = [];
        foreach ($this->getSupportedSites() as $entry) {
            $ids[] = is_array($entry) ? (int)$entry['siteId'] : (int)$entry;
        }
        return $ids;
    }
}

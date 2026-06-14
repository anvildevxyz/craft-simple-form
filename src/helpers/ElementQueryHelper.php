<?php

namespace fabianhaef\simpleform\helpers;

use Craft;
use craft\elements\db\ElementQuery;

/**
 * Standardized site filtering for element queries (Form, Submission).
 */
class ElementQueryHelper
{
    /**
     * @template TKey of array-key
     * @template TElement of \craft\base\ElementInterface
     *
     * @param ElementQuery<TKey, TElement> $query
     * @return ElementQuery<TKey, TElement>
     */
    public static function forCurrentSite(ElementQuery $query): ElementQuery
    {
        return $query->siteId(Craft::$app->getSites()->getCurrentSite()->id);
    }

    /**
     * @template TKey of array-key
     * @template TElement of \craft\base\ElementInterface
     *
     * @param ElementQuery<TKey, TElement> $query
     * @return ElementQuery<TKey, TElement>
     */
    public static function forSite(ElementQuery $query, int $siteId): ElementQuery
    {
        return $query->siteId($siteId);
    }

    /**
     * @template TKey of array-key
     * @template TElement of \craft\base\ElementInterface
     *
     * @param ElementQuery<TKey, TElement> $query
     * @return ElementQuery<TKey, TElement>
     */
    public static function forAllSites(ElementQuery $query): ElementQuery
    {
        return $query->siteId('*');
    }
}

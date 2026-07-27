<?php

namespace anvildev\simpleform\fields;

use craft\elements\Category;
use craft\elements\db\CategoryQuery;
use craft\elements\db\ElementQueryInterface;

/**
 * An element-relation field that lets a visitor select one or more categories,
 * constrained to a configured set of category groups.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class CategoryRelationFieldType extends ElementRelationFieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'category';
    }

    public static function getLabel(): string
    {
        return 'Categories';
    }

    public static function elementType(): string
    {
        return Category::class;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    protected function applySources(ElementQueryInterface $query): void
    {
        $sources = $this->sources();
        if ($sources !== ['*'] && $query instanceof CategoryQuery) {
            $query->group($sources);
        }
    }
}

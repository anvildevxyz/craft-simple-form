<?php

namespace fabianhaef\simpleform\fields;

use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\db\ElementQueryInterface;

/**
 * An element-relation field that lets a visitor select one or more assets,
 * constrained to a configured set of volumes.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class AssetRelationFieldType extends ElementRelationFieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'asset';
    }

    public static function getLabel(): string
    {
        return 'Assets';
    }

    public static function elementType(): string
    {
        return Asset::class;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    protected function applySources(ElementQueryInterface $query): void
    {
        $sources = $this->sources();
        if ($sources !== ['*'] && $query instanceof AssetQuery) {
            $query->volume($sources);
        }
    }
}

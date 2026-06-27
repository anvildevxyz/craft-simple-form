<?php

namespace anvildev\simpleform\fields;

use craft\elements\db\ElementQueryInterface;
use craft\elements\db\TagQuery;
use craft\elements\Tag;

/**
 * An element-relation field that lets a visitor select one or more tags,
 * constrained to a configured set of tag groups.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class TagRelationFieldType extends ElementRelationFieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'tag';
    }

    public static function getLabel(): string
    {
        return 'Tags';
    }

    public static function elementType(): string
    {
        return Tag::class;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    protected function applySources(ElementQueryInterface $query): void
    {
        $sources = $this->sources();
        if ($sources !== ['*'] && $query instanceof TagQuery) {
            $query->group($sources);
        }
    }
}

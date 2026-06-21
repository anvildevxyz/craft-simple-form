<?php

namespace fabianhaef\simpleform\fields;

use craft\elements\db\ElementQueryInterface;
use craft\elements\db\EntryQuery;
use craft\elements\Entry;

/**
 * An element-relation field that lets a visitor select one or more entries,
 * constrained to a configured set of sections.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class EntryRelationFieldType extends ElementRelationFieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'entry';
    }

    public static function getLabel(): string
    {
        return 'Entries';
    }

    public static function elementType(): string
    {
        return Entry::class;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    protected function applySources(ElementQueryInterface $query): void
    {
        $sources = $this->sources();
        if ($sources !== ['*'] && $query instanceof EntryQuery) {
            $query->section($sources);
        }
    }
}

<?php

namespace anvildev\simpleform\fields;

use craft\elements\db\ElementQueryInterface;
use craft\elements\db\UserQuery;
use craft\elements\User;

/**
 * An element-relation field that lets a visitor select one or more users,
 * constrained to a configured set of user groups.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class UserRelationFieldType extends ElementRelationFieldType
{
    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function getType(): string
    {
        return 'user';
    }

    public static function getLabel(): string
    {
        return 'Users';
    }

    public static function elementType(): string
    {
        return User::class;
    }

    // =========================================================================
    // Protected Methods
    // =========================================================================

    protected function applySources(ElementQueryInterface $query): void
    {
        $sources = $this->sources();
        if ($sources !== ['*'] && $query instanceof UserQuery) {
            $query->group($sources);
        }
    }
}

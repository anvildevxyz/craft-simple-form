<?php

namespace anvildev\simpleform\helpers;

use craft\db\Query;

/**
 * Shared form-content helpers used by the clone and import/export paths
 * ({@see \anvildev\simpleform\services\FormCloneService},
 * {@see \anvildev\simpleform\services\FormPortabilityService}), so the
 * per-site content schema and the field/handle lookups have a single home.
 */
class FormContentHelper
{
    /**
     * The per-site form content attributes that are copied/imported as a unit.
     * Add a new translatable content column here and every copy path picks it up.
     *
     * @var list<string>
     */
    public const CONTENT_ATTRS = ['title', 'description', 'emailTo', 'emailSubject', 'emailReplyTo', 'emailBody'];

    /**
     * Whether a form with this exact handle already exists.
     */
    public static function handleExists(string $handle): bool
    {
        return (new Query())
            ->from('{{%simpleform_forms}}')
            ->where(['handle' => $handle])
            ->exists();
    }

    /**
     * Map a form's field ids keyed by handle (the field `name` column).
     *
     * @return array<string, int>
     */
    public static function fieldIdsByHandle(int $formId): array
    {
        return array_map('intval', (new Query())
            ->select(['name', 'id'])
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId])
            ->pairs());
    }
}

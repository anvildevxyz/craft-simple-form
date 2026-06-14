<?php

namespace fabianhaef\simpleform\helpers;

use Craft;
use craft\db\Query;

/**
 * Loads a form's fields with structural columns plus the per-site translatable
 * label/helpText for a given site. Single source of truth for the fields join,
 * reused by the CP edit screen, Twig rendering, and submission handling.
 */
class FieldQueryHelper
{
    /**
     * @return array<int,array<string,mixed>> rows with: id, formId, type, name,
     *   required (bool), config (array, with 'required' merged in for field types),
     *   sortOrder, label (falls back to handle), helpText
     */
    public static function fieldsForForm(int $formId, ?int $siteId = null): array
    {
        $siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;

        $rows = (new Query())
            ->select([
                'f.id',
                'f.formId',
                'f.type',
                'f.name',
                'f.required',
                'f.config',
                'f.sortOrder',
                'fs.label',
                'fs.helpText',
            ])
            ->from(['f' => '{{%simpleform_fields}}'])
            ->leftJoin(
                ['fs' => '{{%simpleform_fields_sites}}'],
                '[[fs.fieldId]] = [[f.id]] AND [[fs.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->where(['f.formId' => $formId])
            ->orderBy(['f.sortOrder' => SORT_ASC])
            ->all();

        foreach ($rows as &$row) {
            $config = $row['config'] ? json_decode($row['config'], true) : [];
            $row['required'] = (bool)$row['required'];
            // Field types read "required" from their config, so expose it there too.
            $config['required'] = $row['required'];
            $row['config'] = $config;
            // Fall back to the field handle when this site has no translated label yet.
            $row['label'] = $row['label'] ?? $row['name'];
        }

        return $rows;
    }
}

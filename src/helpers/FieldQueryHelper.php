<?php

namespace fabianhaef\simpleform\helpers;

use Craft;
use craft\db\Query;

/**
 * Loads a form's fields with structural columns plus the per-site translatable
 * label/helpText for a given site. Single source of truth for the fields join,
 * reused by the CP edit screen, Twig rendering, and submission handling.
 *
 * @phpstan-type ResolvedFieldRow array{id:int, formId:int, type:string, name:string, required:bool, config:array<string,mixed>, sortOrder:int, label:string, helpText:?string, optionLabels?:array<string,string>, errorMessage?:?string}
 */
class FieldQueryHelper
{
    /**
     * @return list<ResolvedFieldRow> rows with: id, formId, type, name,
     *   required (bool), config (array, with 'required' merged in for field types),
     *   sortOrder, label (falls back to handle), helpText. `name` is the field
     *   handle (some consumers re-key it to `handle`).
     */
    public static function fieldsForForm(int $formId, ?int $siteId = null): array
    {
        return self::fieldsForForms([$formId], $siteId)[$formId] ?? [];
    }

    /**
     * Batch-load fields for many forms in a single query (the same join used by
     * {@see self::fieldsForForm()}), grouped by formId. This is the N+1-free path
     * for listing multiple forms: one query for all their fields instead of one
     * query per form.
     *
     * @param int[] $formIds
     * @return array<int,list<ResolvedFieldRow>> formId => rows (see
     *   fieldsForForm() for the per-row shape). Forms with no fields are present
     *   with an empty array.
     */
    public static function fieldsForForms(array $formIds, ?int $siteId = null): array
    {
        $formIds = array_values(array_unique(array_map('intval', $formIds)));

        // Pre-seed every requested form so callers can rely on the key existing.
        $result = array_fill_keys($formIds, []);
        if (empty($formIds)) {
            return $result;
        }

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
                'fs.optionLabels',
                'fs.errorMessage',
            ])
            ->from(['f' => '{{%simpleform_fields}}'])
            ->leftJoin(
                ['fs' => '{{%simpleform_fields_sites}}'],
                '[[fs.fieldId]] = [[f.id]] AND [[fs.siteId]] = :siteId',
                [':siteId' => $siteId]
            )
            ->where(['f.formId' => $formIds])
            ->orderBy(['f.formId' => SORT_ASC, 'f.sortOrder' => SORT_ASC])
            ->all();

        foreach ($rows as $row) {
            $config = $row['config'] ? json_decode($row['config'], true) : [];
            // Guard against malformed/legacy values that don't decode to an array.
            if (!is_array($config)) {
                $config = [];
            }
            $row['required'] = (bool)$row['required'];
            // Field types read "required" from their config, so expose it there too.
            $config['required'] = $row['required'];
            $row['config'] = $config;
            // Fall back to the field handle when this site has no translated label yet.
            $row['label'] = $row['label'] ?? $row['name'];
            // Per-site option-label overrides (value => label) for choice fields.
            $optionLabels = $row['optionLabels'] ? json_decode($row['optionLabels'], true) : [];
            $row['optionLabels'] = is_array($optionLabels) ? $optionLabels : [];

            $result[(int)$row['formId']][] = $row;
        }

        return $result;
    }

    /**
     * Overlay a site's per-site option labels onto a field config's options for
     * rendering: each option keeps its canonical `value`, but its `label` is
     * replaced by the site override when one is present and non-empty. Missing
     * overrides fall back to the source label, so labels are never blank.
     *
     * Pure and side-effect free (no DB, no Craft) so it can be unit-tested and
     * reused by every render surface (Twig, GraphQL).
     *
     * @param array<string,mixed> $config the decoded field config
     * @param array<string,string> $optionLabels value => localized label
     * @return array<string,mixed> the config with localized option labels
     */
    public static function applyOptionLabels(array $config, array $optionLabels): array
    {
        if (empty($optionLabels) || !isset($config['options']) || !is_array($config['options'])) {
            return $config;
        }

        foreach ($config['options'] as &$opt) {
            if (!is_array($opt) || !isset($opt['value'])) {
                continue;
            }
            $value = (string)$opt['value'];
            if (isset($optionLabels[$value]) && $optionLabels[$value] !== '') {
                $opt['label'] = $optionLabels[$value];
            }
        }
        unset($opt);

        return $config;
    }
}

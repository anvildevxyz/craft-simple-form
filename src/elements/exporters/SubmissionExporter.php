<?php

namespace anvildev\simpleform\elements\exporters;

use anvildev\simpleform\helpers\SubmissionCsv;
use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;

/**
 * Native element-index exporter (#109): metadata + one column per field label,
 * selectable from the Submissions index export menu. Craft formats the returned
 * rows to the chosen format (CSV/JSON/XML).
 */
class SubmissionExporter extends ElementExporter
{
    // =========================================================================
    // Public Properties
    // =========================================================================

    /**
     * Stable column keys (see {@see SubmissionCsv::availableColumns()}) to
     * restrict the export to. Empty = every column (#317).
     *
     * @var list<string>
     */
    public array $columns = [];

    // =========================================================================
    // Public Methods
    // =========================================================================

    public static function displayName(): string
    {
        return Craft::t('simple-form', 'Submissions (with field columns)');
    }

    /**
     * Hydrate the filtered submissions in bounded batches rather than materializing
     * the whole result set (#340); {@see SubmissionCsv::toRowsFromQuery()} keeps the
     * output byte-for-byte identical to the former `$query->all()` path.
     *
     * @return array<int, array<string, string>>
     */
    public function export(ElementQueryInterface $query): mixed
    {
        return SubmissionCsv::toRowsFromQuery($query, $this->columns ?: null);
    }
}

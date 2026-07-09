<?php

namespace anvildev\simpleform\elements\exporters;

use anvildev\simpleform\elements\Submission;
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
     * Header labels to restrict the export to. Empty = every column (#317).
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
     * @return array<int, array<string, string>>
     */
    public function export(ElementQueryInterface $query): mixed
    {
        $submissions = array_values(array_filter(
            $query->all(),
            static fn($element): bool => $element instanceof Submission,
        ));

        return SubmissionCsv::toRows($submissions, $this->columns ?: null);
    }
}

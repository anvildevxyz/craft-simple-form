<?php

namespace fabianhaef\simpleform\elements\exporters;

use Craft;
use craft\base\ElementExporter;
use craft\elements\db\ElementQueryInterface;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\helpers\SubmissionCsv;

/**
 * Native element-index exporter (#109): metadata + one column per field label,
 * selectable from the Submissions index export menu. Craft formats the returned
 * rows to the chosen format (CSV/JSON/XML).
 */
class SubmissionExporter extends ElementExporter
{
    public static function displayName(): string
    {
        return Craft::t('simple-form', 'Submissions (with field columns)');
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function export(ElementQueryInterface $query): mixed
    {
        $submissions = [];
        foreach ($query->all() as $element) {
            if ($element instanceof Submission) {
                $submissions[] = $element;
            }
        }

        return SubmissionCsv::toRows($submissions);
    }
}

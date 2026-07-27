<?php

namespace anvildev\simpleform\fields;

/**
 * How a field's submitted values roll up in the survey report (#240).
 *
 * Each field type names its own kind through {@see FieldType::aggregation()},
 * so {@see \anvildev\simpleform\services\ReportsService::fieldReport()} builds
 * the report straight from the form's field set with no hardcoded type list.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
enum AggregationKind: string
{
    /** Closed-option fields (select/radio/checkbox): a count per chosen option. */
    case Choice = 'choice';

    /** Numeric scale fields (rating/opinion): a value distribution + average. */
    case Scale = 'scale';

    /** Free-form / file / signature: a response count only, never a chart. */
    case None = 'none';
}

<?php

namespace fabianhaef\simpleform\events;

use fabianhaef\simpleform\helpers\FieldQueryHelper;
use yii\base\Event;

/**
 * Fired from {@see \fabianhaef\simpleform\services\FormStructureService::getFieldSet()}
 * so a handler can add, remove, reorder or rewrite the resolved field rows for a
 * form on a given site before they are rendered or validated. Mutate
 * {@see self::$fields} in place:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_DEFINE_FIELD_SET,
 *     function(DefineFieldSetEvent $e): void {
 *         $e->fields = array_filter($e->fields, fn($row) => $row['handle'] !== 'secret');
 *     }
 * );
 * ```
 *
 * The event only fires when a handler is attached, so the cached fast path is
 * unaffected for installs that don't extend the field set.
 *
 * @phpstan-import-type ResolvedFieldRow from FieldQueryHelper
 * @since 1.0.0
 */
class DefineFieldSetEvent extends Event
{
    public int $formId;

    public int $siteId;

    /** @var list<ResolvedFieldRow> */
    public array $fields = [];
}

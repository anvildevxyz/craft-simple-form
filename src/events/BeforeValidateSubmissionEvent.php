<?php

namespace fabianhaef\simpleform\events;

use fabianhaef\simpleform\elements\Form;
use yii\base\Event;

/**
 * Fired from {@see \fabianhaef\simpleform\services\SubmissionService} after the
 * submitted values have been resolved into a handle-keyed snapshot but *before*
 * any field is validated. A handler can inspect or rewrite
 * {@see self::$valuesByHandle} — the map validation, conditional-visibility and
 * storage all read from — to normalize input, inject server-derived values, or
 * apply custom rules:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_BEFORE_VALIDATE,
 *     function(BeforeValidateSubmissionEvent $e): void {
 *         if (isset($e->valuesByHandle['email'])) {
 *             $e->valuesByHandle['email'] = strtolower(trim($e->valuesByHandle['email']));
 *         }
 *     }
 * );
 * ```
 *
 * Fires for both new submissions and edits, on every channel (front-end,
 * GraphQL, MCP), since they share the validate core.
 *
 * @since 2.12.0
 */
class BeforeValidateSubmissionEvent extends Event
{
    public Form $form;

    /**
     * The raw submitted values keyed by field id (read-only snapshot).
     *
     * @var array<int|string, mixed>
     */
    public array $values = [];

    /**
     * The submitted values keyed by field handle. Mutating this map changes what
     * is validated, what conditional rules see, and what is stored.
     *
     * @var array<string, mixed>
     */
    public array $valuesByHandle = [];

    /**
     * The submit context (siteId, userId, …) as passed to the service.
     *
     * @var array<string, mixed>
     */
    public array $context = [];

    /** Whether this is a new submission (false for an edit). */
    public bool $isNew = true;
}

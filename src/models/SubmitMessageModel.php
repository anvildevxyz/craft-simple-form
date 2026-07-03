<?php

namespace anvildev\simpleform\models;

use craft\base\Model;

/**
 * One conditional submit message attached to a form (#265): a confirmation
 * ("thank you") message gated by a condition reusing the conditional-logic
 * engine (the same `field`/`operator`/`value`/`all`/`any` shape as field
 * visibility and notification send-conditions).
 *
 * The rule/priority ({@see $conditional}, {@see $sortOrder}) is shared/structural;
 * the message text is per-site translatable, so the model carries both an
 * authoring map keyed by site id ({@see $messages}) and a single resolved value
 * for one site context ({@see $message}) — mirroring the shared-vs-per-site split
 * used by {@see FieldModel}.
 *
 * At submit time {@see \anvildev\simpleform\services\SubmissionService::resolvePostSubmit()}
 * evaluates a form's rows in {@see $sortOrder} and the first whose condition
 * matches the submitted values wins; if none match, the form's default
 * `submitMessage` is used, unchanged.
 *
 * @author Fabian Haefliger
 * @since 2.14.0
 */
class SubmitMessageModel extends Model
{
    // Public Properties
    // =========================================================================

    public ?int $id = null;
    public ?int $formId = null;
    /** @var array<string, mixed>|null Conditional config ({enabled, match, action, rules}). */
    public ?array $conditional = null;
    public ?int $sortOrder = null;
    public ?string $uid = null;
    /**
     * Per-site message text keyed by site id — the authoring representation the
     * service persists to `{{%simpleform_submit_messages_sites}}`.
     *
     * @var array<int, string>
     */
    public array $messages = [];
    /**
     * The resolved per-site message for a single site context, populated by
     * {@see \anvildev\simpleform\services\SubmitMessagesService::getForFormAndSite()}.
     * Null when no translation exists for that site.
     */
    public ?string $message = null;

    // Protected Methods
    // =========================================================================

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['formId'], 'required'],
            [['formId', 'sortOrder'], 'integer'],
        ];
    }
}

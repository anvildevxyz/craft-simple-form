<?php

namespace fabianhaef\simpleform\models;

use craft\base\Model;

/**
 * One email notification attached to a form (#112): an admin alert (fixed
 * recipient) or an autoresponder (recipient taken from a form field), optionally
 * gated by a send condition reusing the conditional-logic engine.
 */
class NotificationModel extends Model
{
    public const RECIPIENT_FIXED = 'fixed';
    public const RECIPIENT_FIELD = 'field';

    public ?int $id = null;
    public ?int $formId = null;
    public string $name = '';
    public bool $enabled = true;
    /** 'fixed' (literal address(es)) or 'field' (a field handle holding the email). */
    public string $recipientType = self::RECIPIENT_FIXED;
    /** Comma/space-separated addresses for 'fixed', or a field handle for 'field'. */
    public string $recipient = '';
    public ?string $subject = null;
    public ?string $replyTo = null;
    public ?string $body = null;
    /** @var array<string, mixed>|null Conditional config ({enabled, match, action, rules}). */
    public ?array $conditional = null;
    public ?int $sortOrder = null;
    public ?string $uid = null;

    /**
     * @return array<int, array<int|string, mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['formId', 'name', 'recipient'], 'required'],
            [['formId', 'sortOrder'], 'integer'],
            [['name', 'recipient', 'subject', 'replyTo'], 'string', 'max' => 255],
            [['enabled'], 'boolean'],
            [['recipientType'], 'in', 'range' => [self::RECIPIENT_FIXED, self::RECIPIENT_FIELD]],
        ];
    }
}

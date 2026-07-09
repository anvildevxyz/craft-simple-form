<?php

namespace anvildev\simpleform\models;

use anvildev\simpleform\Plugin;
use Craft;
use craft\base\Model;
use yii\validators\EmailValidator;

/**
 * One email notification attached to a form (#112): an admin alert (fixed
 * recipient) or an autoresponder (recipient taken from a form field), optionally
 * gated by a send condition reusing the conditional-logic engine.
 *
 * A notification may also carry attachments (#143): a rendered PDF of the
 * submission ({@see $attachPdf}) and/or the submission's uploaded files
 * ({@see $attachUploads}). The PDF toggle cannot be enabled unless a PDF engine
 * (dompdf) is installed.
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
    /** Comma-separated CC address list (#313). */
    public ?string $cc = null;
    /** Comma-separated BCC address list (#313). */
    public ?string $bcc = null;
    public ?string $body = null;
    /** @var array<string, mixed>|null Conditional config ({enabled, match, action, rules}). */
    public ?array $conditional = null;
    /** Attach a rendered PDF of the submission to this notification (#143). */
    public bool $attachPdf = false;
    /** Attach the submission's uploaded files to this notification (#143). */
    public bool $attachUploads = false;
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
            [['name', 'recipient', 'subject', 'replyTo', 'cc', 'bcc'], 'string', 'max' => 255],
            // F11 (CWE-93): validate replyTo as an email so a CRLF/header-
            // injection value is rejected before it reaches the mailer.
            [['replyTo'], 'email', 'when' => fn(self $model): bool => $model->replyTo !== null && $model->replyTo !== ''],
            // #313 (CWE-93): CC/BCC are comma-separated lists; validate each
            // address as an email so a CRLF/header-injection part is rejected
            // before it reaches the mailer.
            [['cc', 'bcc'], 'validateAddressList'],
            [['enabled', 'attachPdf', 'attachUploads'], 'boolean'],
            [['recipientType'], 'in', 'range' => [self::RECIPIENT_FIXED, self::RECIPIENT_FIELD]],
            // #143: a PDF attachment cannot be enabled without a PDF engine; the
            // CP toggle is disabled in that case, but guard the server side too so
            // a crafted POST can't persist attachPdf=true with no way to render.
            [['attachPdf'], 'validatePdfAvailable'],
        ];
    }

    /**
     * Reject a comma-separated address list when any part is not a valid email
     * (#313, CWE-93). A header-injection payload such as a CRLF followed by a
     * forged `Bcc:` header fails the email test and is rejected.
     */
    public function validateAddressList(string $attribute): void
    {
        $value = $this->$attribute;
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        $validator = new EmailValidator();
        foreach (preg_split('/[\s,;]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $address) {
            if (!$validator->validate($address)) {
                $this->addError($attribute, Craft::t(
                    'simple-form',
                    '“{value}” is not a valid email address.',
                    ['value' => $address],
                ));
                return;
            }
        }
    }

    /**
     * Reject enabling the PDF attachment when no PDF engine is installed.
     */
    public function validatePdfAvailable(string $attribute): void
    {
        if ($this->attachPdf && !Plugin::getInstance()->getPdf()->isAvailable()) {
            $this->addError($attribute, Craft::t(
                'simple-form',
                'Install the dompdf library to attach a submission PDF.',
            ));
        }
    }
}

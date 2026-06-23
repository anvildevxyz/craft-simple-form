<?php

namespace fabianhaef\simpleform\events;

use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\NotificationModel;
use yii\base\Event;

/**
 * Fired from {@see \fabianhaef\simpleform\services\EmailService} before each
 * resolved notification email is sent. A handler can rewrite the recipient list
 * or suppress the notification entirely by setting {@see self::$send} to false:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_BEFORE_SEND_NOTIFICATION,
 *     function(BeforeSendNotificationEvent $e): void {
 *         if (($e->submissionData['field_12'] ?? '') === 'internal') {
 *             $e->send = false; // skip this one notification
 *         }
 *     }
 * );
 * ```
 *
 * The submission's stored field data is exposed as {@see self::$submissionData}
 * (not `$data`, which Yii's base Event reserves for handler-attached data).
 *
 * @since 2.12.0
 */
class BeforeSendNotificationEvent extends Event
{
    public Form $form;

    public Submission $submission;

    /**
     * The resolved notification, or null for the legacy single-recipient path
     * driven by the form's own email columns (no NotificationModel).
     */
    public ?NotificationModel $notification = null;

    /**
     * The notification's recipient addresses. Mutating this list changes who the
     * email is sent to.
     *
     * @var list<string>
     */
    public array $recipients = [];

    /**
     * The submission's stored field data.
     *
     * @var array<string, mixed>
     */
    public array $submissionData = [];

    /** Set to false to suppress this notification. */
    public bool $send = true;
}

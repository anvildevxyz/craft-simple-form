<?php

namespace anvildev\simpleform\stencils;

use anvildev\simpleform\events\RegisterStencilsEvent;
use anvildev\simpleform\models\NotificationModel;
use anvildev\simpleform\Plugin;
use Craft;
use yii\base\Component;

/**
 * The registry of built-in form stencils plus any contributed via
 * {@see Plugin::EVENT_REGISTER_STENCILS}. Stencils are pure data templates; the
 * write path lives in
 * {@see \anvildev\simpleform\services\FormCloneService::createFromStencil()}.
 *
 * @since 1.0.0
 * @author Fabian Haefliger
 */
class StencilLibrary extends Component
{
    // =========================================================================
    // Private Properties
    // =========================================================================

    /** @var array<string,Stencil>|null memoized handle => stencil map */
    private ?array $_stencils = null;

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * Every registered stencil (built-in + event-contributed), keyed by handle.
     *
     * @return array<string,Stencil>
     */
    public function getAll(): array
    {
        if ($this->_stencils !== null) {
            return $this->_stencils;
        }

        $stencils = $this->builtIn();

        $event = new RegisterStencilsEvent();
        Plugin::getInstance()->trigger(Plugin::EVENT_REGISTER_STENCILS, $event);
        foreach ($event->stencils as $stencil) {
            $stencils[] = $stencil;
        }

        $byHandle = [];
        foreach ($stencils as $stencil) {
            // Later registrations (events) override an earlier built-in handle.
            $byHandle[$stencil->handle] = $stencil;
        }

        return $this->_stencils = $byHandle;
    }

    /**
     * Look up a single stencil by handle, or null when none is registered.
     */
    public function getByHandle(string $handle): ?Stencil
    {
        return $this->getAll()[$handle] ?? null;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * The four built-in starters described in the stencils PRD.
     *
     * @return array<int,Stencil>
     */
    private function builtIn(): array
    {
        return [
            new Stencil([
                'handle' => 'contact',
                'name' => Craft::t('simple-form', 'Contact'),
                'description' => Craft::t('simple-form', 'Name, email and message — the classic contact form.'),
                'fields' => [
                    $this->field('text', 'name', Craft::t('simple-form', 'Name'), true),
                    $this->field('email', 'email', Craft::t('simple-form', 'Email'), true),
                    $this->field('textarea', 'message', Craft::t('simple-form', 'Message'), true),
                ],
                'notifications' => [
                    $this->adminAlert(Craft::t('simple-form', 'New contact submission')),
                ],
            ]),
            new Stencil([
                'handle' => 'newsletter',
                'name' => Craft::t('simple-form', 'Newsletter signup'),
                'description' => Craft::t('simple-form', 'Collect an email address with a consent checkbox.'),
                'fields' => [
                    $this->field('email', 'email', Craft::t('simple-form', 'Email'), true),
                    $this->field('checkbox', 'consent', Craft::t('simple-form', 'I agree to receive emails'), true, [
                        'options' => [
                            ['value' => 'yes', 'label' => Craft::t('simple-form', 'Yes')],
                        ],
                    ]),
                ],
            ]),
            new Stencil([
                'handle' => 'event-registration',
                'name' => Craft::t('simple-form', 'Event registration'),
                'description' => Craft::t('simple-form', 'Register attendees with guest count and dietary notes.'),
                'fields' => [
                    $this->field('text', 'name', Craft::t('simple-form', 'Name'), true),
                    $this->field('email', 'email', Craft::t('simple-form', 'Email'), true),
                    $this->field('number', 'guests', Craft::t('simple-form', 'Number of guests')),
                    $this->field('textarea', 'dietaryNotes', Craft::t('simple-form', 'Dietary notes')),
                    $this->field('select', 'attending', Craft::t('simple-form', 'Attending?'), true, [
                        'options' => [
                            ['value' => 'yes', 'label' => Craft::t('simple-form', 'Yes')],
                            ['value' => 'no', 'label' => Craft::t('simple-form', 'No')],
                            ['value' => 'maybe', 'label' => Craft::t('simple-form', 'Maybe')],
                        ],
                    ]),
                ],
                'notifications' => [
                    $this->adminAlert(Craft::t('simple-form', 'New event registration')),
                ],
            ]),
            new Stencil([
                'handle' => 'support-request',
                'name' => Craft::t('simple-form', 'Support request'),
                'description' => Craft::t('simple-form', 'A triage form with priority plus an autoresponder.'),
                'fields' => [
                    $this->field('text', 'name', Craft::t('simple-form', 'Name'), true),
                    $this->field('email', 'email', Craft::t('simple-form', 'Email'), true),
                    $this->field('select', 'priority', Craft::t('simple-form', 'Priority'), true, [
                        'options' => [
                            ['value' => 'low', 'label' => Craft::t('simple-form', 'Low')],
                            ['value' => 'normal', 'label' => Craft::t('simple-form', 'Normal')],
                            ['value' => 'high', 'label' => Craft::t('simple-form', 'High')],
                        ],
                    ]),
                    $this->field('text', 'subject', Craft::t('simple-form', 'Subject'), true),
                    $this->field('textarea', 'details', Craft::t('simple-form', 'Details'), true),
                ],
                'notifications' => [
                    $this->adminAlert(Craft::t('simple-form', 'New support request')),
                    [
                        'name' => Craft::t('simple-form', 'Autoresponder'),
                        'recipientType' => NotificationModel::RECIPIENT_FIELD,
                        // Resolved against the copy's own 'email' field handle.
                        'recipient' => 'email',
                        'subject' => Craft::t('simple-form', 'We received your support request'),
                    ],
                ],
            ]),
        ];
    }

    /**
     * Build a single field in the sync-item shape.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private function field(string $type, string $handle, string $label, bool $required = false, array $config = []): array
    {
        return [
            'type' => $type,
            'handle' => $handle,
            'label' => $label,
            'required' => $required,
            'config' => $config,
        ];
    }

    /**
     * An admin-alert notification whose recipient resolves to the form's primary
     * email recipient at create time (left blank here; the create flow fills it
     * with the configured default sender so the form works out of the box).
     *
     * @return array<string,mixed>
     */
    private function adminAlert(string $subject): array
    {
        return [
            'name' => Craft::t('simple-form', 'Admin notification'),
            'recipientType' => NotificationModel::RECIPIENT_FIXED,
            // Filled with the system default email by the create flow.
            'recipient' => '',
            'subject' => $subject,
        ];
    }
}

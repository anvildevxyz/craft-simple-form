<?php

namespace anvildev\simpleform\events;

use anvildev\simpleform\elements\Submission;
use craft\elements\User;
use yii\base\Event;

/**
 * Fired after a submission moves between workflow stages (#248), so handlers can
 * send notifications or dispatch integrations on a transition without the plugin
 * hardcoding any. Carries the submission, the from/to stage handles, and the
 * acting user (null for a programmatic move).
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class WorkflowTransitionEvent extends Event
{
    public function __construct(
        public Submission $submission,
        public ?string $from,
        public string $to,
        public ?User $user = null,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}

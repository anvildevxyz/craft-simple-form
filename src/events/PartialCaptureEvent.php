<?php

namespace anvildev\simpleform\events;

use anvildev\simpleform\elements\Form;
use yii\base\Event;

/**
 * Fired after a passive partial is captured (#244), so integrators can build
 * their own abandonment follow-up (a CRM ping, a "you left something behind"
 * email, …). The plugin itself sends nothing for a partial.
 *
 * `values` is the field_<id> => value map captured so far; `token` addresses the
 * stored partial (its hash is what's persisted); `siteId` is the capture site.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class PartialCaptureEvent extends Event
{
    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $config
     */
    public function __construct(
        public Form $form,
        public array $values,
        public int $siteId,
        public string $token,
        array $config = [],
    ) {
        parent::__construct($config);
    }
}

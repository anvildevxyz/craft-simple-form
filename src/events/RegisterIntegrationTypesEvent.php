<?php

namespace fabianhaef\simpleform\events;

use yii\base\Event;

/**
 * Fired from {@see \fabianhaef\simpleform\services\IntegrationTypeRegistry} so
 * third parties can register their own integration-type classes:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_REGISTER_INTEGRATION_TYPES,
 *     fn(RegisterIntegrationTypesEvent $e) => $e->types[] = MyConnector::class,
 * );
 * ```
 */
class RegisterIntegrationTypesEvent extends Event
{
    /** @var array<int, class-string<\fabianhaef\simpleform\integrations\IntegrationTypeInterface>> */
    public array $types = [];
}

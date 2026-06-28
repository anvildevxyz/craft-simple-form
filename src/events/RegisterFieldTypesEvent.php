<?php

namespace anvildev\simpleform\events;

use yii\base\Event;

/**
 * Fired from {@see \anvildev\simpleform\services\FieldTypeRegistry} so third
 * parties can register their own field-type classes the same way they register
 * integration types, captcha providers and stencils:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_REGISTER_FIELD_TYPES,
 *     fn(RegisterFieldTypesEvent $e) => $e->types[] = MyFieldType::class,
 * );
 * ```
 *
 * Calling {@see FieldTypeRegistry::registerFieldType()} from your own `init()`
 * still works and remains supported; this event is the recommended, uniform
 * entry point.
 *
 * @since 1.0.0
 */
class RegisterFieldTypesEvent extends Event
{
    /** @var array<int, class-string<\anvildev\simpleform\fields\FieldType>> */
    public array $types = [];
}

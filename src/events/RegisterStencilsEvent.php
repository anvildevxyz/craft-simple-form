<?php

namespace anvildev\simpleform\events;

use yii\base\Event;

/**
 * Fired from {@see \anvildev\simpleform\stencils\StencilLibrary} so third
 * parties can contribute their own form stencils:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_REGISTER_STENCILS,
 *     fn(RegisterStencilsEvent $e) => $e->stencils[] = new Stencil([
 *         'handle' => 'feedback',
 *         'name' => 'Feedback',
 *         'fields' => [...],
 *     ]),
 * );
 * ```
 *
 * @since 1.0.0
 * @author Anvil Dev
 */
class RegisterStencilsEvent extends Event
{
    /** @var array<int,\anvildev\simpleform\stencils\Stencil> */
    public array $stencils = [];
}

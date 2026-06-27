<?php

namespace fabianhaef\simpleform\events;

use fabianhaef\simpleform\elements\Form;
use yii\base\Event;

/**
 * Fired from {@see \fabianhaef\simpleform\services\FormRenderService::buildContext()}
 * so a handler can add to or rewrite the Twig render context before a form (or a
 * single field/start/end fragment) is rendered. Mutate {@see self::$context} in
 * place:
 *
 * ```php
 * Event::on(
 *     Plugin::class,
 *     Plugin::EVENT_MODIFY_RENDER_CONTEXT,
 *     function(ModifyRenderContextEvent $e): void {
 *         $e->context['mySetting'] = true; // available to overridden partials
 *     }
 * );
 * ```
 *
 * Security-sensitive context values (`csrfInput`, `honeypot`, `captcha`) are
 * pre-rendered Markup; replacing them does not regenerate the underlying tokens.
 *
 * @since 1.0.0
 */
class ModifyRenderContextEvent extends Event
{
    public Form $form;

    /** @var array<string, mixed> */
    public array $context = [];
}

<?php

namespace fabianhaef\simpleform\events;

use yii\base\Event;

/**
 * Fired from {@see \fabianhaef\simpleform\services\CaptchaProviderRegistry} so
 * third parties can register their own captcha providers.
 */
class RegisterCaptchaProvidersEvent extends Event
{
    /** @var array<int, class-string<\fabianhaef\simpleform\captcha\CaptchaProviderInterface>> */
    public array $providers = [];
}

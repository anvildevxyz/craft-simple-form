<?php

namespace anvildev\simpleform\events;

use yii\base\Event;

/**
 * Fired from {@see \anvildev\simpleform\services\CaptchaProviderRegistry} so
 * third parties can register their own captcha providers.
 */
class RegisterCaptchaProvidersEvent extends Event
{
    /** @var array<int, class-string<\anvildev\simpleform\captcha\CaptchaProviderInterface>> */
    public array $providers = [];
}

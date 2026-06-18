<?php

namespace fabianhaef\simpleform\services;

use fabianhaef\simpleform\captcha\CaptchaProviderInterface;
use fabianhaef\simpleform\captcha\RecaptchaProvider;
use fabianhaef\simpleform\events\RegisterCaptchaProvidersEvent;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Registry of available captcha providers. The core reCAPTCHA provider is
 * registered in {@see init()}; third parties (and the first-party Turnstile /
 * hCaptcha providers) add their own. Mirrors {@see IntegrationTypeRegistry}.
 */
class CaptchaProviderRegistry extends Component
{
    public const DEFAULT_PROVIDER = 'recaptcha';

    /** @var array<string, class-string<CaptchaProviderInterface>> */
    private array $providers = [];

    public function init(): void
    {
        parent::init();

        $this->registerProvider(RecaptchaProvider::class);

        if (!class_exists(\Craft::class) || \Craft::$app === null) {
            return;
        }

        $plugin = Plugin::getInstance();
        if ($plugin !== null) {
            $event = new RegisterCaptchaProvidersEvent();
            $plugin->trigger(Plugin::EVENT_REGISTER_CAPTCHA_PROVIDERS, $event);
            foreach ($event->providers as $class) {
                $this->registerProvider($class);
            }
        }
    }

    /**
     * @param class-string<CaptchaProviderInterface> $class
     */
    public function registerProvider(string $class): void
    {
        if (!class_exists($class)) {
            throw new \InvalidArgumentException("Captcha provider class does not exist: $class");
        }
        if (!is_subclass_of($class, CaptchaProviderInterface::class)) {
            throw new \InvalidArgumentException("Captcha provider must implement CaptchaProviderInterface: $class");
        }

        $this->providers[$class::handle()] = $class;
    }

    public function getProvider(string $handle): ?CaptchaProviderInterface
    {
        if (!isset($this->providers[$handle])) {
            return null;
        }
        $class = $this->providers[$handle];
        return new $class();
    }

    /**
     * Resolve the configured provider, falling back to the default (reCAPTCHA)
     * when the setting is empty or names an unknown provider.
     */
    public function resolve(string $handle): CaptchaProviderInterface
    {
        return $this->getProvider($handle)
            ?? $this->getProvider(self::DEFAULT_PROVIDER)
            ?? new RecaptchaProvider();
    }

    /**
     * Handle => display-name map for the settings picker.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->providers as $handle => $class) {
            $out[$handle] = $class::displayName();
        }
        return $out;
    }
}

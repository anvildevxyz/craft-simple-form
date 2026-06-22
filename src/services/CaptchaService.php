<?php

namespace fabianhaef\simpleform\services;

use fabianhaef\simpleform\captcha\CaptchaProviderInterface;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\Plugin;
use yii\base\Component;

/**
 * Captcha verification, delegated to the selected provider
 * (see {@see CaptchaProviderRegistry}). Stays a thin facade so callers
 * (SubmissionService, TwigExtension) don't depend on the concrete provider.
 */
class CaptchaService extends Component
{
    /**
     * Verify the captcha token on the current request.
     *
     * Returns true when captcha is disabled, so callers can verify unconditionally.
     */
    public function verify(?string $token = null): bool
    {
        $settings = $this->getSettings();

        return !$settings->enableCaptcha || $this->provider()->verify($token, $settings);
    }

    /**
     * Render the selected provider's widget, or '' when captcha is disabled.
     */
    public function renderWidget(): string
    {
        $settings = $this->getSettings();

        return $settings->enableCaptcha ? $this->provider()->renderWidget($settings) : '';
    }

    /** The provider selected in settings (falls back to the default). */
    public function provider(): CaptchaProviderInterface
    {
        return Plugin::getInstance()
            ->getCaptchaProviderRegistry()
            ->resolve($this->getSettings()->selectedCaptchaProvider);
    }

    private function getSettings(): Settings
    {
        return Plugin::getInstance()->getSettings();
    }
}

<?php

namespace modules\simpleform\examples;

use Craft;
use fabianhaef\simpleform\captcha\CaptchaProviderInterface;
use fabianhaef\simpleform\models\Settings;

/**
 * Example custom captcha provider: a tiny "what is X + Y?" arithmetic challenge.
 * Demonstrates the CaptchaProviderInterface — render a widget and verify the
 * response server-side — without any third-party service. (Illustrative; a real
 * deployment should prefer reCAPTCHA / hCaptcha / Turnstile.)
 *
 * Register it from your plugin/module init():
 *
 *   \yii\base\Event::on(
 *       \fabianhaef\simpleform\Plugin::class,
 *       \fabianhaef\simpleform\Plugin::EVENT_REGISTER_CAPTCHA_PROVIDERS,
 *       fn($e) => $e->providers[] = \modules\simpleform\examples\MathCaptchaProvider::class,
 *   );
 */
class MathCaptchaProvider implements CaptchaProviderInterface
{
    public static function handle(): string
    {
        return 'math';
    }

    public static function displayName(): string
    {
        return 'Math question (example)';
    }

    public function tokenParam(): string
    {
        return 'mathCaptcha';
    }

    public function renderWidget(Settings $settings): string
    {
        // Store the expected answer in the session and ask the question. (A real
        // provider would sign/expire the challenge; this keeps the example short.)
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        Craft::$app->getSession()->set('sf.mathCaptcha', $a + $b);

        return sprintf(
            '<label>What is %d + %d? <input type="text" name="%s" inputmode="numeric" required></label>',
            $a,
            $b,
            htmlspecialchars($this->tokenParam(), ENT_QUOTES),
        );
    }

    public function verify(?string $token, Settings $settings): bool
    {
        $token ??= Craft::$app->getRequest()->getBodyParam($this->tokenParam());
        $expected = Craft::$app->getSession()->get('sf.mathCaptcha');
        Craft::$app->getSession()->remove('sf.mathCaptcha');

        return $expected !== null && (int) $token === (int) $expected;
    }
}

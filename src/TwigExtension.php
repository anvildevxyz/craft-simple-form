<?php

namespace fabianhaef\simpleform;

use Craft;
use craft\helpers\App;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\models\Settings;
use fabianhaef\simpleform\web\assets\form\FormAsset;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('simpleForm', [$this, 'renderForm'], [
                'is_safe' => ['html'],
                'needs_environment' => false,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function renderForm(string $handle, array $options = []): string
    {
        if (empty($handle)) {
            return '<!-- Form handle is required -->';
        }

        $form = Form::find()
            ->handle($handle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        if (!$form) {
            Craft::warning(sprintf('Form "%s" not found for Twig rendering', $handle), 'simple-form');
            return sprintf('<!-- Form "%s" not found -->', htmlspecialchars($handle));
        }

        $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();

        // Resolved field set (decoded config + per-site label/helpText), served
        // from the structure cache when enabled. The CSRF input and captcha
        // markup below are injected per-request and are NOT part of this cache.
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int)$form->id, $siteId);

        $html = '<form class="simple-form" method="POST" action="' . Craft::$app->getUrlManager()->createUrl('simple-form/submit') . '">';
        $html .= Craft::$app->getView()->renderString('{{ csrfInput() }}');
        $html .= '<input type="hidden" name="formHandle" value="' . htmlspecialchars($handle) . '">';

        $settings = Plugin::getInstance()->getSettings();

        // Add honeypot field when enabled
        if ($settings->enableHoneypot) {
            $html .= '<input type="hidden" name="__honeypot" value="" style="display:none;">';
        }

        // Render form fields
        foreach ($fields as $field) {
            $fieldConfig = $field['config']; // already decoded, with "required" merged in
            $fieldType = $fieldTypeRegistry->getFieldType($field['type'], $fieldConfig);

            if (!$fieldType) {
                continue;
            }

            $label = $field['label'] ?? $field['name'];
            $helpText = $field['helpText'] ?? '';
            $fieldName = 'field_' . $field['id'];

            $html .= '<div class="simple-form-group">';
            if ($label) {
                $required = !empty($fieldConfig['required']) ? ' <span class="required">*</span>' : '';
                $html .= '<label for="' . htmlspecialchars($fieldName) . '">' . htmlspecialchars($label) . $required . '</label>';
            }
            if ($helpText) {
                $html .= '<small class="help-text">' . htmlspecialchars($helpText) . '</small>';
            }

            $html .= '<div class="input-wrapper">';
            $html .= $fieldType->renderInput($fieldName);
            $html .= '</div>';
            $html .= '</div>';
        }

        // Captcha widget when enabled
        $html .= $this->renderCaptcha($settings);

        // Submit button
        $submitText = $options['submitText'] ?? 'Submit';
        $html .= '<button type="submit" class="simple-form-submit-btn">' . htmlspecialchars($submitText) . '</button>';

        $html .= '</form>';

        // Form CSS/JS: registered as a cache-bustable asset bundle by default
        // (no asset weight on form-less pages), with an inline escape hatch.
        $html .= $this->renderAssets($settings);

        return $html;
    }

    /**
     * Output the form's CSS/JS.
     *
     * By default this registers the {@see FormAsset} bundle (versioned,
     * browser-cacheable, only loaded on pages that render a form) and returns an
     * empty string. When the `inlineFormAssets` setting is on — or when no
     * active web View is available to register against — it falls back to
     * emitting the same CSS/JS inline so output stays self-contained.
     */
    private function renderAssets(Settings $settings): string
    {
        $view = Craft::$app->getView();

        if (!$settings->inlineFormAssets) {
            try {
                $view->registerAssetBundle(FormAsset::class);
                return '';
            } catch (\Throwable $e) {
                // Fall through to inline output (e.g. console/test contexts where
                // the asset manager can't publish).
                Craft::warning('Falling back to inline form assets: ' . $e->getMessage(), 'simple-form');
            }
        }

        $css = @file_get_contents(FormAsset::distPath('css/simple-form.css')) ?: '';
        $js = @file_get_contents(FormAsset::distPath('js/simple-form.js')) ?: '';

        return '<style>' . $css . '</style>' . '<script>' . $js . '</script>';
    }

    /**
     * Render the reCAPTCHA widget markup for the configured captcha type, or an
     * empty string when captcha is disabled or unconfigured.
     */
    private function renderCaptcha(Settings $settings): string
    {
        if (!$settings->enableCaptcha) {
            return '';
        }

        $siteKey = $settings->getActiveSiteKey();
        if (!$siteKey) {
            return '';
        }
        // Site keys may be stored as env references; resolve before output.
        $siteKey = App::parseEnv($siteKey);
        if (!$siteKey) {
            return '';
        }
        $siteKey = htmlspecialchars((string) $siteKey, ENT_QUOTES);

        if ($settings->captchaType === Settings::CAPTCHA_V2) {
            // The v2 widget injects its own `g-recaptcha-response` field on submit.
            return '<div class="simple-form-group">'
                . '<div class="g-recaptcha" data-sitekey="' . $siteKey . '"></div>'
                . '</div>'
                . '<script src="https://www.google.com/recaptcha/api.js" async defer></script>';
        }

        // v3 is invisible: keep a fresh token in a hidden field that rides along
        // with the form\'s existing fetch submit.
        return '<input type="hidden" name="g-recaptcha-response" value="">'
            . '<script src="https://www.google.com/recaptcha/api.js?render=' . $siteKey . '"></script>'
            . '<script>
                (function() {
                    var siteKey = "' . $siteKey . '";
                    function refreshToken() {
                        if (typeof grecaptcha === "undefined") { return; }
                        grecaptcha.ready(function() {
                            grecaptcha.execute(siteKey, { action: "submit" }).then(function(token) {
                                document.querySelectorAll("input[name=\'g-recaptcha-response\']").forEach(function(input) {
                                    input.value = token;
                                });
                            });
                        });
                    }
                    refreshToken();
                    // Tokens expire after ~2 minutes; refresh well before that.
                    setInterval(refreshToken, 90000);
                })();
            </script>';
    }
}

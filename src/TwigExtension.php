<?php

namespace fabianhaef\simpleform;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
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

        // A multipart enctype is required for file fields on the no-JS POST path
        // (the JS fetch+FormData submit is already multipart).
        $hasFileField = false;
        foreach ($fields as $field) {
            if (($field['type'] ?? null) === 'file') {
                $hasFileField = true;
                break;
            }
        }
        $enctype = $hasFileField ? ' enctype="multipart/form-data"' : '';

        $html = '<form class="simple-form" method="POST"' . $enctype . ' action="' . Craft::$app->getUrlManager()->createUrl('simple-form/submit') . '">';
        $html .= Craft::$app->getView()->renderString('{{ csrfInput() }}');
        $html .= '<input type="hidden" name="formHandle" value="' . htmlspecialchars($handle) . '">';

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->enableHoneypot) {
            $html .= '<input type="hidden" name="__honeypot" value="" style="display:none;">';
        }

        foreach ($fields as $field) {
            // already decoded, with "required" merged in; overlay this site's
            // per-site option labels (value stays canonical, label localized).
            $fieldConfig = FieldQueryHelper::applyOptionLabels(
                $field['config'],
                is_array($field['optionLabels'] ?? null) ? $field['optionLabels'] : []
            );
            $fieldType = $fieldTypeRegistry->getFieldType($field['type'], $fieldConfig);

            if (!$fieldType) {
                continue;
            }

            $label = $field['label'] ?? $field['name'];
            $helpText = $field['helpText'] ?? '';
            $fieldName = 'field_' . $field['id'];

            // Tag every group with its field handle so the front-end evaluator
            // can build a handle => value map; carry the conditional rules (when
            // enabled) on the group so it can show/hide and toggle required live.
            $groupAttrs = ' data-sf-handle="' . htmlspecialchars((string) $field['name'], ENT_QUOTES) . '"';
            $conditional = $fieldConfig['conditional'] ?? null;
            if (is_array($conditional) && !empty($conditional['enabled'])) {
                $groupAttrs .= ' data-sf-conditional="'
                    . htmlspecialchars((string) json_encode($conditional), ENT_QUOTES) . '"';
            }

            $html .= '<div class="simple-form-group"' . $groupAttrs . '>';
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

        $html .= $this->renderCaptcha($settings);

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
     * Render the selected captcha provider's widget, or an empty string when
     * captcha is disabled/unconfigured. Delegates to the provider via
     * {@see CaptchaService} so new captcha types need no change here.
     */
    private function renderCaptcha(Settings $settings): string
    {
        if (!$settings->enableCaptcha) {
            return '';
        }

        return Plugin::getInstance()->getCaptchaService()->renderWidget();
    }
}

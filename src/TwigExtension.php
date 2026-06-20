<?php

namespace fabianhaef\simpleform;

use Craft;
use craft\helpers\UrlHelper;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\helpers\FormSteps;
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

        // Access gates (#135): a guest sees the login-required message + link, a
        // capped user sees the limit-reached message — both instead of the form.
        // The server still re-checks every gate in SubmissionService::submit(), so
        // a page cached before login (or a crafted POST) is rejected there too.
        $accessNotice = $this->renderAccessNotice($form);
        if ($accessNotice !== null) {
            return $accessNotice;
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

        // Expose the configured (localized) error message so the JS submit
        // handler can show it if the request fails with a non-JSON response.
        $errorMessage = (string) (Plugin::getInstance()->getSettings()->errorMessage ?? '');
        $errorAttr = $errorMessage !== '' ? ' data-sf-error="' . htmlspecialchars($errorMessage, ENT_QUOTES) . '"' : '';

        $steps = FormSteps::group($fields);
        $submitText = $options['submitText'] ?? 'Submit';

        // Save-&-resume: only on multi-step forms that opted in. When the page is
        // loaded with a valid ?sfresume=<token>, prefill the saved values and keep
        // the token so re-saving updates the same draft.
        $resumeEnabled = $form->allowSaveResume && count($steps) > 1;
        $resumeValues = [];
        $resumeToken = '';
        if ($form->allowSaveResume) {
            /** @var \craft\web\Request $request */
            $request = Craft::$app->getRequest();
            $token = (string) $request->getParam('sfresume', '');
            if ($token !== '') {
                $saved = Plugin::getInstance()->getDrafts()->getData($token, (int) $form->id);
                if ($saved !== null) {
                    $resumeValues = $saved;
                    $resumeToken = $token;
                }
            }
        }

        $resumeAttr = '';
        if ($resumeEnabled) {
            $resumeAttr = ' data-sf-resume="' . htmlspecialchars(Craft::$app->getUrlManager()->createUrl('simple-form/submit/save-draft'), ENT_QUOTES) . '"'
                . ' data-sf-resume-label="' . htmlspecialchars(Craft::t('simple-form', 'Saved. Use this link to continue later:'), ENT_QUOTES) . '"'
                . ' data-sf-resume-copy="' . htmlspecialchars(Craft::t('simple-form', 'Copy'), ENT_QUOTES) . '"'
                . ' data-sf-resume-copied="' . htmlspecialchars(Craft::t('simple-form', 'Copied'), ENT_QUOTES) . '"'
                . ($resumeToken !== '' ? ' data-sf-resume-token="' . htmlspecialchars($resumeToken, ENT_QUOTES) . '"' : '');
        }

        $html = '<form class="simple-form" method="POST"' . $enctype . $errorAttr . $resumeAttr . ' action="' . Craft::$app->getUrlManager()->createUrl('simple-form/submit') . '">';
        $html .= Craft::$app->getView()->renderString('{{ csrfInput() }}');
        $html .= '<input type="hidden" name="formHandle" value="' . htmlspecialchars($handle) . '">';

        $settings = Plugin::getInstance()->getSettings();

        if ($settings->enableHoneypot) {
            $html .= '<input type="hidden" name="__honeypot" value="" style="display:none;" aria-hidden="true" autocomplete="off">';
        }

        if (count($steps) <= 1) {
            // Single page — unchanged markup.
            foreach ($fields as $field) {
                $html .= $this->renderFieldGroup($field, $fieldTypeRegistry, $resumeValues);
            }
            $html .= $this->renderCaptcha($settings);
            $html .= '<button type="submit" class="simple-form-submit-btn">' . htmlspecialchars($submitText) . '</button>';
        } else {
            // Multi-step: one container per page; the JS reveals one at a time and
            // drives next/back + per-step validation. The captcha + submit live on
            // the last step.
            $lastIndex = count($steps) - 1;
            foreach ($steps as $i => $stepFields) {
                $hidden = $i === 0 ? '' : ' hidden';
                $html .= '<div class="simple-form-step" data-sf-step="' . $i . '"' . $hidden . '>';
                foreach ($stepFields as $field) {
                    $html .= $this->renderFieldGroup($field, $fieldTypeRegistry, $resumeValues);
                }
                if ($i === $lastIndex) {
                    $html .= $this->renderCaptcha($settings);
                }
                $html .= '</div>';
            }

            $html .= '<div class="simple-form-step-nav" data-sf-multistep="' . count($steps) . '">';
            $html .= '<button type="button" class="simple-form-step-back" hidden>'
                . htmlspecialchars(Craft::t('simple-form', 'Back')) . '</button>';
            $html .= '<button type="button" class="simple-form-step-next">'
                . htmlspecialchars(Craft::t('simple-form', 'Next')) . '</button>';
            $html .= '<button type="submit" class="simple-form-submit-btn" hidden>' . htmlspecialchars($submitText) . '</button>';
            if ($resumeEnabled) {
                $html .= '<button type="button" class="simple-form-save-resume">'
                    . htmlspecialchars(Craft::t('simple-form', 'Save & continue later')) . '</button>';
            }
            $html .= '<span class="simple-form-step-progress" role="status" aria-live="polite"></span>';
            $html .= '</div>';
        }

        $html .= '</form>';

        // Form CSS/JS: registered as a cache-bustable asset bundle by default
        // (no asset weight on form-less pages), with an inline escape hatch.
        $html .= $this->renderAssets($settings);

        return $html;
    }

    /**
     * Build the access-gate notice shown instead of the form (#135), or null when
     * the visitor may proceed:
     *  - login required + guest → the login-required message plus a login link
     *    that returns to the current URL after sign-in;
     *  - logged-in user at/over the per-user cap → the limit-reached message.
     *
     * The server re-checks both gates on submit, so this is purely a render-time
     * convenience (a cached/forged request is still rejected there).
     */
    private function renderAccessNotice(Form $form): ?string
    {
        $user = Craft::$app->getUser();

        if ($form->requireLogin && $user->getIsGuest()) {
            /** @var \craft\web\Request $request */
            $request = Craft::$app->getRequest();
            $loginUrl = UrlHelper::siteUrl(
                Craft::$app->getConfig()->getGeneral()->getLoginPath(),
                ['return' => $request->getAbsoluteUrl()]
            );

            return '<div class="simple-form simple-form--login-required" role="status">'
                . htmlspecialchars($form->getLoginRequiredMessage())
                . ' <a href="' . htmlspecialchars($loginUrl) . '">'
                . htmlspecialchars(Craft::t('simple-form', 'Log in')) . '</a></div>';
        }

        $userId = $user->getId();
        if (Plugin::getInstance()->getSubmissionService()->userHasReachedLimit($form, $userId !== null ? (int) $userId : null)) {
            return '<div class="simple-form simple-form--limit-reached" role="status">'
                . htmlspecialchars($form->getUserLimitMessage()) . '</div>';
        }

        return null;
    }

    /**
     * Render one field's group markup (label, help text, input), including the
     * conditional-logic data attributes the front-end evaluator reads.
     *
     * @param array<string, mixed> $field a resolved field row
     * @param array<string, mixed> $values prefill values (field_<id> => value), for resume
     */
    private function renderFieldGroup(array $field, \fabianhaef\simpleform\services\FieldTypeRegistry $fieldTypeRegistry, array $values = []): string
    {
        // already decoded, with "required" merged in; overlay this site's
        // per-site option labels (value stays canonical, label localized).
        $fieldConfig = FieldQueryHelper::applyOptionLabels(
            $field['config'],
            is_array($field['optionLabels'] ?? null) ? $field['optionLabels'] : []
        );
        $fieldType = $fieldTypeRegistry->getFieldType($field['type'], $fieldConfig);

        if (!$fieldType) {
            return '';
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

        // Choice groups (radio/checkbox) carry many inputs, so the group is
        // labelled with a role="group" + aria-labelledby pointing at a span;
        // single controls keep a <label for> tied to the input's id (#105).
        $isChoice = $fieldType->isChoiceGroup();
        $labelId = $fieldName . '-label';

        $html = '<div class="simple-form-group"' . $groupAttrs . '>';
        if ($label) {
            // The required marker is decorative — the control's `required`
            // attribute is what assistive tech announces.
            $required = !empty($fieldConfig['required'])
                ? ' <span class="required" aria-hidden="true">*</span>'
                : '';
            if ($isChoice) {
                $html .= '<span class="simple-form-label" id="' . htmlspecialchars($labelId) . '">'
                    . htmlspecialchars($label) . $required . '</span>';
            } else {
                $html .= '<label for="' . htmlspecialchars($fieldName) . '">'
                    . htmlspecialchars($label) . $required . '</label>';
            }
        }
        if ($helpText) {
            $html .= '<small class="help-text">' . htmlspecialchars($helpText) . '</small>';
        }

        if ($isChoice) {
            $wrapAttrs = ' role="group"';
            if ($label) {
                $wrapAttrs .= ' aria-labelledby="' . htmlspecialchars($labelId) . '"';
            }
            $html .= '<div class="input-wrapper"' . $wrapAttrs . '>';
        } else {
            $html .= '<div class="input-wrapper">';
        }
        $html .= $fieldType->renderInput($fieldName, $values[$fieldName] ?? null);
        $html .= '</div>';
        $html .= '</div>';

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

<?php

namespace fabianhaef\simpleform;

use Craft;
use craft\helpers\App;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\FieldQueryHelper;
use fabianhaef\simpleform\models\Settings;
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

        // Get form fields with the current site's translatable label/helpText.
        $fields = FieldQueryHelper::fieldsForForm((int)$form->id);

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

        // Add CSS
        $html .= '<style>
            .simple-form {
                max-width: 500px;
                margin: 20px 0;
            }
            .simple-form-group {
                margin-bottom: 20px;
            }
            .simple-form-group label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
            }
            .simple-form-group .required {
                color: red;
            }
            .simple-form-group .help-text {
                display: block;
                margin-top: 5px;
                font-size: 0.9em;
                color: #666;
            }
            .simple-form-group input[type="text"],
            .simple-form-group input[type="email"],
            .simple-form-group input[type="date"],
            .simple-form-group input[type="number"],
            .simple-form-group textarea,
            .simple-form-group select {
                width: 100%;
                padding: 10px;
                border: 1px solid #ccc;
                border-radius: 4px;
                font-family: inherit;
                font-size: 1em;
            }
            .simple-form-group textarea {
                resize: vertical;
            }
            .simple-form-submit-btn {
                background-color: #0066cc;
                color: white;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                cursor: pointer;
                font-size: 1em;
            }
            .simple-form-submit-btn:hover {
                background-color: #0052a3;
            }
        </style>';

        // Add JS for form submission and validation
        $html .= '<script>
            document.querySelectorAll(".simple-form").forEach(form => {
                form.addEventListener("submit", function(e) {
                    e.preventDefault();
                    const formData = new FormData(form);
                    fetch(form.action, {
                        method: "POST",
                        body: formData,
                        headers: {
                            "X-Requested-With": "XMLHttpRequest"
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message || "Form submitted successfully!");
                            form.reset();
                        } else if (data.errors) {
                            Object.keys(data.errors).forEach(fieldKey => {
                                const errorMessages = data.errors[fieldKey];
                                const fieldElement = form.querySelector("[name=\"" + fieldKey + "\"]");
                                if (fieldElement) {
                                    const errorDiv = document.createElement("div");
                                    errorDiv.className = "form-error";
                                    errorDiv.style.color = "red";
                                    errorDiv.style.fontSize = "0.9em";
                                    errorDiv.style.marginTop = "5px";
                                    errorDiv.innerHTML = errorMessages.join("<br>");
                                    fieldElement.parentNode.appendChild(errorDiv);
                                }
                            });
                        }
                    })
                    .catch(error => console.error("Form submission error:", error));
                });
            });
        </script>';

        return $html;
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
        $siteKey = htmlspecialchars($siteKey, ENT_QUOTES);

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

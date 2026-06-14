<?php

namespace fabianhaef\simpleform;

use Craft;
use fabianhaef\simpleform\elements\Form;
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
        $form = Form::find()
            ->handle($handle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        if (!$form) {
            return sprintf('<!-- Form "%s" not found -->', htmlspecialchars($handle));
        }

        $fieldTypeRegistry = Plugin::getInstance()->getFieldTypeRegistry();

        // Get form fields from database
        $db = Craft::$app->getDb();
        $fields = $db->createCommand(
            'SELECT id, type, name, label, helpText, config FROM {{%simpleform_fields}} WHERE formId = :formId ORDER BY sortOrder ASC'
        )
            ->bindValues([':formId' => $form->id])
            ->queryAll();

        $html = '<form class="simple-form" method="POST" action="' . Craft::$app->getUrlManager()->createUrl('simple-form/submit') . '">';
        $html .= Craft::$app->getView()->renderString('{{ csrfInput() }}');
        $html .= '<input type="hidden" name="formHandle" value="' . htmlspecialchars($handle) . '">';

        // Add honeypot field
        $html .= '<input type="hidden" name="__honeypot" value="" style="display:none;">';

        // Render form fields
        foreach ($fields as $field) {
            $fieldConfig = $field['config'] ? json_decode($field['config'], true) : [];
            $fieldType = $fieldTypeRegistry->getFieldType($field['type'], $fieldConfig);

            if (!$fieldType) {
                continue;
            }

            $label = $field['label'] ?? $field['name'];
            $helpText = $field['helpText'] ?? '';
            $fieldName = 'field_' . $field['id'];

            $html .= '<div class="simple-form-group">';
            if ($label) {
                $required = $fieldConfig['required'] ? ' <span class="required">*</span>' : '';
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
}

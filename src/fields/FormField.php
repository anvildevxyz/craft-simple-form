<?php

namespace fabianhaef\simpleform\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use fabianhaef\simpleform\elements\Form;
use yii\db\Schema;

/**
 * A custom field that embeds a Simple Form form in any element's field layout
 * (#108). Either lock the field to one form in its settings, or leave it open so
 * authors pick a form per entry. The stored value normalizes to the Form element,
 * so `entry.myForm.handle` works and it can be rendered with
 * `simpleForm(entry.myForm.handle)` / `craft.simpleForm.render(...)`.
 */
class FormField extends Field
{
    /**
     * Lock this field to a specific form id. 0/null = let the author choose per
     * entry. (0 is the "author chooses" sentinel so the settings <select> can post
     * a valid int into the typed property.)
     */
    public ?int $formId = null;

    public static function displayName(): string
    {
        return Craft::t('simple-form', 'Form');
    }

    public static function icon(): string
    {
        return 'rectangle-list';
    }

    public static function dbType(): string
    {
        return Schema::TYPE_INTEGER;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($value instanceof Form) {
            return $value;
        }

        $id = $this->formId ?: (is_numeric($value) ? (int) $value : null);
        if (!$id) {
            return null;
        }

        return Form::find()->id($id)->siteId('*')->status(null)->one();
    }

    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if ($this->formId) {
            return $this->formId;
        }
        if ($value instanceof Form) {
            return (int) $value->id;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('simple-form/_fields/form-settings', [
            'field' => $this,
            'forms' => $this->formOptions(),
        ]);
    }

    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        if ($this->formId) {
            $form = $value instanceof Form
                ? $value
                : Form::find()->id($this->formId)->siteId('*')->status(null)->one();

            return Craft::$app->getView()->renderTemplate('simple-form/_fields/form-input', [
                'locked' => true,
                'formName' => $form->name ?? ('#' . $this->formId),
            ]);
        }

        return Craft::$app->getView()->renderTemplate('simple-form/_fields/form-input', [
            'locked' => false,
            'id' => $this->getInputId(),
            'name' => $this->handle,
            'options' => $this->formOptions(true),
            'value' => $value instanceof Form ? (int) $value->id : '',
        ]);
    }

    /**
     * @return list<array{label: string, value: int}>
     */
    private function formOptions(bool $withBlank = false): array
    {
        $options = $withBlank ? [['label' => Craft::t('simple-form', 'Choose a form…'), 'value' => 0]] : [];
        foreach (Form::find()->siteId('*')->status(null)->all() as $form) {
            $options[] = ['label' => (string) $form->name, 'value' => (int) $form->id];
        }

        return $options;
    }
}

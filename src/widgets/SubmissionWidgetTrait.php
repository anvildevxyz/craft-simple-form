<?php

namespace anvildev\simpleform\widgets;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\helpers\SimpleFormPermissions;
use Craft;
use craft\helpers\Cp;

/**
 * Shared scaffolding for the submission dashboard widgets: the "Form" scope
 * picker and the view-submissions permission gate, both identical across them.
 */
trait SubmissionWidgetTrait
{
    /**
     * Options for the widget's "Form" picker: "All forms" plus every form on the
     * current site.
     *
     * @return list<array{label: string, value: string}>
     */
    private function formScopeOptions(): array
    {
        $options = [['label' => Craft::t('simple-form', 'All forms'), 'value' => '']];
        foreach (Form::find()->siteId(Craft::$app->getSites()->getCurrentSite()->id)->all() as $form) {
            $options[] = ['label' => (string) ($form->title ?? $form->name), 'value' => (string) $form->id];
        }
        return $options;
    }

    /** The "Form" scope picker for a widget's settings, shared across the submission widgets. */
    private function formScopeFieldHtml(): string
    {
        return Cp::selectFieldHtml([
            'label' => Craft::t('simple-form', 'Form'),
            'name' => 'formId',
            'options' => $this->formScopeOptions(),
            'value' => (string) ($this->formId ?? ''),
        ]);
    }

    /** The widget-body permission message, or null when the user may view submissions. */
    private function submissionsPermissionError(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission(SimpleFormPermissions::VIEW_SUBMISSIONS)) {
            return Craft::t('simple-form', 'You don’t have permission to view submissions.');
        }
        return null;
    }
}

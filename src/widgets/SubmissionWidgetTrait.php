<?php

namespace fabianhaef\simpleform\widgets;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\SimpleFormPermissions;

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

    /** The widget-body permission message, or null when the user may view submissions. */
    private function submissionsPermissionError(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission(SimpleFormPermissions::VIEW_SUBMISSIONS)) {
            return Craft::t('simple-form', 'You don’t have permission to view submissions.');
        }
        return null;
    }
}

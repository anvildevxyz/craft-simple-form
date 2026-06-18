<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\web\View;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\fields\FormField;

/**
 * The custom "Form" field (#108): normalizes to the Form element, serializes back
 * to its id, honours a locked form, and is registered as a Craft field type.
 */
class FormFieldTest extends SimpleFormTestCase
{
    private function inCp(callable $fn): mixed
    {
        $view = Craft::$app->getView();
        $mode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        try {
            return $fn();
        } finally {
            $view->setTemplateMode($mode);
        }
    }

    public function testRegisteredAsFieldType(): void
    {
        $this->requireCraft();
        $this->assertContains(FormField::class, Craft::$app->getFields()->getAllFieldTypes());
    }

    public function testOpenFieldRoundTripsForm(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'formfield_open');

        $field = new FormField();
        $field->handle = 'myForm';

        $normalized = $field->normalizeValue((int) $form->id, null);
        $this->assertInstanceOf(Form::class, $normalized);
        $this->assertSame('formfield_open', $normalized->handle);

        $this->assertSame((int) $form->id, $field->serializeValue($normalized, null));
        $this->assertNull($field->normalizeValue(null, null), 'empty value normalizes to null when not locked');
    }

    public function testLockedFieldAlwaysResolvesConfiguredForm(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Locked', 'formfield_locked');

        $field = new FormField();
        $field->formId = (int) $form->id;

        // Even with no stored value, a locked field resolves its configured form.
        $normalized = $field->normalizeValue(null, null);
        $this->assertInstanceOf(Form::class, $normalized);
        $this->assertSame('formfield_locked', $normalized->handle);

        // And serialization always reports the locked id.
        $this->assertSame((int) $form->id, $field->serializeValue(null, null));
    }

    public function testRendersSettingsAndInput(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Render', 'formfield_render');

        $this->inCp(function() use ($form): void {
            $open = new FormField();
            $open->handle = 'myForm';
            $settings = (string) $open->getSettingsHtml();
            $this->assertStringContainsString('formId', $settings);

            $input = $open->getInputHtml(null, null);
            $this->assertStringContainsString('<select', $input);

            $locked = new FormField();
            $locked->formId = (int) $form->id;
            $lockedInput = $locked->getInputHtml($locked->normalizeValue(null, null), null);
            $this->assertStringContainsString('Render', $lockedInput);
        });
    }
}

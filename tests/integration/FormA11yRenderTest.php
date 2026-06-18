<?php

namespace fabianhaef\simpleform\tests\integration;

use fabianhaef\simpleform\TwigExtension;

/**
 * #105 — the rendered form wires labels/ids accessibly: single controls get a
 * <label for> tied to the control id; choice groups become role="group" +
 * aria-labelledby; the required marker is decorative (aria-hidden).
 *
 * @group requires-craft
 */
class FormA11yRenderTest extends SimpleFormTestCase
{
    public function testRenderedFormHasAccessibleLabelsAndChoiceGroup(): void
    {
        $this->requireCraft();

        $form = $this->createForm('A11y', 'a11y_form');
        $textId = $this->createField($form->id, 'text', 'fullName', 'Full Name', true);
        $radioId = $this->createField($form->id, 'radio', 'plan', 'Plan', true, [
            'options' => [
                ['value' => 'basic', 'label' => 'Basic'],
                ['value' => 'pro', 'label' => 'Pro'],
            ],
        ]);

        $html = (new TwigExtension())->renderForm('a11y_form');

        // Single control: <label for> targets the control's id.
        $this->assertStringContainsString('<label for="field_' . $textId . '">', $html);
        $this->assertStringContainsString('id="field_' . $textId . '"', $html);

        // Required marker is decorative — announced via the control's `required`.
        $this->assertStringContainsString('<span class="required" aria-hidden="true">*</span>', $html);

        // Choice group: role=group + aria-labelledby + per-option label/id.
        $this->assertStringContainsString('role="group"', $html);
        $this->assertStringContainsString('aria-labelledby="field_' . $radioId . '-label"', $html);
        $this->assertStringContainsString('id="field_' . $radioId . '-label"', $html);
        $this->assertStringContainsString('<label for="field_' . $radioId . '-0">Basic</label>', $html);
        $this->assertStringContainsString('id="field_' . $radioId . '-1"', $html);
    }
}

<?php

namespace fabianhaef\simpleform\tests\smoke;

use SmokeTester;

/**
 * Form Rendering Smoke Tests (functional).
 *
 * Exercises the public Twig `simpleForm()` render path end-to-end: HTML
 * structure, CSRF + honeypot, and per-field markup for every field type. Forms
 * and fields are seeded through the data layer (see {@see BaseSmokeCest}); the
 * render runs through the real Twig function a site template would call.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FormRenderingCest extends BaseSmokeCest
{
    // =========================================================================
    // PRIVATE PROPERTIES
    // =========================================================================

    private string $formHandle;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $form = $this->createForm('Form Rendering Test', 'renderTest' . uniqid(), 'admin@test.com');
        $this->formHandle = $form->handle;
    }

    public function testFormRendersBasicHtml(SmokeTester $I): void
    {
        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('class="simple-form"', $html);
        $I->assertStringContainsString('method="POST"', $html);
        $I->assertStringContainsString('action="/simple-form/submit"', $html);
        $I->assertStringContainsString('type="submit"', $html);
        $I->assertStringContainsString('class="simple-form-submit-btn"', $html);
    }

    public function testFormIncludesCsrfToken(SmokeTester $I): void
    {
        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('csrf', strtolower($html), 'Should render the CSRF input');
        $I->assertStringContainsString('type="hidden"', $html);
    }

    public function testFormIncludesHoneypot(SmokeTester $I): void
    {
        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('name="__honeypot"', $html);
        $I->assertStringContainsString('display:none', $html);
    }

    public function testFormIncludesFormHandle(SmokeTester $I): void
    {
        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('name="formHandle"', $html);
        $I->assertStringContainsString('value="' . $this->formHandle . '"', $html);
    }

    public function testTextFieldRendering(SmokeTester $I): void
    {
        $this->createField($this->fieldFormId(), 'text', 'username', 'Username', false, [
            'minLength' => 3,
            'maxLength' => 50,
        ], 'Enter your username');

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('Username', $html);
        $I->assertStringContainsString('Enter your username', $html);
        $I->assertStringContainsString('type="text"', $html);
        $I->assertStringContainsString('name="field_', $html);
    }

    public function testEmailFieldRendering(SmokeTester $I): void
    {
        $this->createField($this->fieldFormId(), 'email', 'email', 'Email Address', true);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('Email Address', $html);
        $I->assertStringContainsString('type="email"', $html);
        $I->assertStringContainsString('<span class="required">*</span>', $html);
    }

    public function testTextareaFieldRendering(SmokeTester $I): void
    {
        $this->createField($this->fieldFormId(), 'textarea', 'message', 'Your Message', false, ['minLength' => 10]);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('Your Message', $html);
        $I->assertStringContainsString('<textarea', $html);
    }

    public function testSelectFieldRendering(SmokeTester $I): void
    {
        $this->createField($this->fieldFormId(), 'select', 'country', 'Country', false, [
            'options' => [
                ['label' => 'USA', 'value' => 'us'],
                ['label' => 'Canada', 'value' => 'ca'],
            ],
        ]);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('Country', $html);
        $I->assertStringContainsString('<select', $html);
        $I->assertStringContainsString('<option', $html);
        $I->assertStringContainsString('USA', $html);
        $I->assertStringContainsString('Canada', $html);
        $I->assertStringContainsString('value="us"', $html);
        $I->assertStringContainsString('value="ca"', $html);
    }

    public function testCheckboxFieldRendering(SmokeTester $I): void
    {
        $this->createField($this->fieldFormId(), 'checkbox', 'interests', 'Interests', false, [
            'options' => [
                ['label' => 'Sports', 'value' => 'sports'],
                ['label' => 'Music', 'value' => 'music'],
            ],
        ]);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('Interests', $html);
        $I->assertStringContainsString('type="checkbox"', $html);
        $I->assertStringContainsString('Sports', $html);
        $I->assertStringContainsString('Music', $html);
    }

    public function testRadioFieldRendering(SmokeTester $I): void
    {
        $this->createField($this->fieldFormId(), 'radio', 'ageGroup', 'Age Group', false, [
            'options' => [
                ['label' => '18-25', 'value' => '18-25'],
                ['label' => '26-35', 'value' => '26-35'],
            ],
        ]);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('Age Group', $html);
        $I->assertStringContainsString('type="radio"', $html);
        $I->assertStringContainsString('18-25', $html);
        $I->assertStringContainsString('26-35', $html);
    }

    public function testDateFieldRendering(SmokeTester $I): void
    {
        $this->createField($this->fieldFormId(), 'date', 'eventDate', 'Event Date');

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('Event Date', $html);
        $I->assertStringContainsString('type="date"', $html);
    }

    public function testNumberFieldRendering(SmokeTester $I): void
    {
        $this->createField($this->fieldFormId(), 'number', 'quantity', 'Quantity', false, ['min' => 1, 'max' => 100]);

        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('Quantity', $html);
        $I->assertStringContainsString('type="number"', $html);
    }

    public function testFormWithAllFieldTypes(SmokeTester $I): void
    {
        $formId = $this->fieldFormId();
        $labels = ['Name', 'Email', 'Message'];
        $this->createField($formId, 'text', 'name', 'Name');
        $this->createField($formId, 'email', 'email', 'Email');
        $this->createField($formId, 'textarea', 'message', 'Message');

        $html = $this->renderForm($this->formHandle);

        foreach ($labels as $label) {
            $I->assertStringContainsString($label, $html, 'Should render ' . $label);
        }
    }

    public function testFormNotFound(SmokeTester $I): void
    {
        $html = $this->renderForm('nonExistentForm');

        $I->assertStringContainsString('not found', $html);
        $I->assertStringNotContainsString('<form', $html, 'No form element for an unknown handle');
    }

    public function testFormWithNoFields(SmokeTester $I): void
    {
        $html = $this->renderForm($this->formHandle);

        $I->assertStringContainsString('simple-form', $html);
        $I->assertStringContainsString('type="submit"', $html);
    }

    public function testFormIncludesInlineCss(SmokeTester $I): void
    {
        // The default render registers the FormAsset bundle; inlineFormAssets
        // emits the CSS/JS straight into the markup, which is what a static
        // (cached) page or a bundle-less render relies on.
        $html = $this->renderForm($this->formHandle, ['inlineFormAssets' => true]);

        $I->assertStringContainsString('<style', $html);
        $I->assertStringContainsString('.simple-form', $html);
    }

    public function testFormIncludesJavaScript(SmokeTester $I): void
    {
        $html = $this->renderForm($this->formHandle, ['inlineFormAssets' => true]);

        $I->assertStringContainsString('<script', $html);
        $I->assertStringContainsString('fetch', $html);
        $I->assertStringContainsString('addEventListener', $html);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function fieldFormId(): int
    {
        return (int)\fabianhaef\simpleform\elements\Form::find()
            ->handle($this->formHandle)
            ->one()
            ->id;
    }
}

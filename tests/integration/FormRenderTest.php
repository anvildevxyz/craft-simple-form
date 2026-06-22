<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use Twig\Markup;

/**
 * Custom render templates (#137): theme resolution precedence + per-partial
 * fallthrough, the documented render context, default-output parity, the
 * formStart/formEnd/field granularity API, and graceful degradation on a bogus
 * path. All exercised against the built-in partials and an on-disk site theme
 * under tests/_craft/templates/_sf-theme*.
 */
class FormRenderTest extends SimpleFormTestCase
{
    /**
     * Seed a form with one text field and a current-site field set.
     */
    private function seedForm(string $handle, ?string $templatePath = null, bool $useCustomTemplate = false): Form
    {
        $form = $this->createForm('Contact', $handle);
        $this->createField((int) $form->id, 'text', 'name', 'Your name', true);

        // A per-form path implies opting into custom templating.
        $useCustomTemplate = $useCustomTemplate || $templatePath !== null;

        if ($useCustomTemplate) {
            $form->templatePath = $templatePath;
            $form->useCustomTemplate = true;
            Craft::$app->getElements()->saveElement($form);
        }

        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        return $form;
    }

    public function testDefaultOutputUnchanged(): void
    {
        $this->requireCraft();
        $this->seedForm('render_default');

        $html = Plugin::getInstance()->getFormRender()->renderForm('render_default');

        $this->assertStringContainsString('<form class="simple-form" method="POST"', $html);
        $this->assertStringContainsString('name="formHandle" value="render_default"', $html);
        $this->assertStringContainsString('class="simple-form-group" data-sf-handle="name"', $html);
        $this->assertStringContainsString('<label for="field_', $html);
        $this->assertStringContainsString('class="simple-form-submit-btn"', $html);
        $this->assertStringContainsString('</form>', $html);
        // No custom path => no theme wrapper.
        $this->assertStringNotContainsString('class="my-field"', $html);
    }

    public function testPerFormThemeOverridesField(): void
    {
        $this->requireCraft();
        $this->seedForm('render_themed', '_sf-theme');

        $html = Plugin::getInstance()->getFormRender()->renderForm('render_themed');

        // The field partial is overridden...
        $this->assertStringContainsString('class="my-field"', $html);
        // ...but form.twig falls through to the built-in (still a real <form>).
        $this->assertStringContainsString('<form class="simple-form"', $html);
        $this->assertStringContainsString('name="formHandle" value="render_themed"', $html);
    }

    public function testGlobalThemeHonouredAndPerFormWins(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->templatePath;
        $settings->templatePath = '_sf-theme';

        try {
            // The form opts into custom templating but sets no per-form path, so
            // it falls back to the global default.
            $this->seedForm('render_global', null, true);
            $html = Plugin::getInstance()->getFormRender()->renderForm('render_global');
            $this->assertStringContainsString('class="my-field"', $html, 'Global path should apply.');

            // theme option overrides for this render only.
            $plain = Plugin::getInstance()->getFormRender()->renderForm('render_global', ['theme' => '']);
            $this->assertStringNotContainsString('class="my-field"', $plain);
        } finally {
            $settings->templatePath = $original;
        }
    }

    public function testGlobalIgnoredWhenSwitchOff(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->templatePath;
        $settings->templatePath = '_sf-theme';

        try {
            // Switch off: the global default must NOT apply — built-in markup only.
            $this->seedForm('render_optout');
            $html = Plugin::getInstance()->getFormRender()->renderForm('render_optout');
            $this->assertStringNotContainsString('class="my-field"', $html);
            $this->assertStringContainsString('<form class="simple-form"', $html);
        } finally {
            $settings->templatePath = $original;
        }
    }

    public function testPerFormPathIgnoredWhenSwitchOff(): void
    {
        $this->requireCraft();

        // A stored per-form path must be ignored entirely when the switch is off:
        // the gate short-circuits to built-in markup before the path is consulted.
        $form = $this->createForm('Contact', 'render_path_off');
        $this->createField((int) $form->id, 'text', 'name', 'Your name', true);
        $form->templatePath = '_sf-theme';
        $form->useCustomTemplate = false;
        Craft::$app->getElements()->saveElement($form);
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        $html = Plugin::getInstance()->getFormRender()->renderForm('render_path_off');

        $this->assertStringNotContainsString('class="my-field"', $html, 'Stored path must be ignored when the switch is off.');
        $this->assertStringContainsString('<form class="simple-form"', $html);
    }

    public function testInvalidPathDegradesGracefully(): void
    {
        $this->requireCraft();
        $this->seedForm('render_bogus', '_does/not/exist');

        $html = Plugin::getInstance()->getFormRender()->renderForm('render_bogus');

        // Falls back to the built-in partials — a real form, no exception.
        $this->assertStringContainsString('<form class="simple-form"', $html);
        $this->assertStringContainsString('name="formHandle" value="render_bogus"', $html);
    }

    public function testThemeRenderOptionOverridesPerRender(): void
    {
        $this->requireCraft();
        $this->seedForm('render_opt');

        $html = Plugin::getInstance()->getFormRender()->renderForm('render_opt', ['theme' => '_sf-theme']);
        $this->assertStringContainsString('class="my-field"', $html);
    }

    public function testRenderContextHasDocumentedKeysAndTypes(): void
    {
        $this->requireCraft();
        $form = $this->seedForm('render_ctx');

        $context = Plugin::getInstance()->getFormRender()->buildContext($form);

        foreach (['form', 'handle', 'fields', 'steps', 'options', 'csrfInput', 'honeypot', 'captcha', 'assets', 'partials', 'action'] as $key) {
            $this->assertArrayHasKey($key, $context, "context missing $key");
        }

        $this->assertInstanceOf(Markup::class, $context['csrfInput']);
        $this->assertInstanceOf(Markup::class, $context['captcha']);
        $this->assertInstanceOf(Markup::class, $context['assets']);
        $this->assertIsArray($context['partials']);
        $this->assertSame('simple-form/form', $context['partials']['form']);
    }

    public function testFormStartEndProduceWorkingForm(): void
    {
        $this->requireCraft();
        $this->seedForm('render_split');

        $render = Plugin::getInstance()->getFormRender();
        $start = (string) $render->renderFormStart('render_split');
        $field = (string) $render->renderField('render_split', 'name');
        $end = (string) $render->renderFormEnd('render_split');

        // Start carries the plumbing.
        $this->assertStringContainsString('<form class="simple-form"', $start);
        $this->assertStringContainsString('name="formHandle" value="render_split"', $start);
        $this->assertStringContainsString('csrf', strtolower($start));
        // End closes the form with a submit.
        $this->assertStringContainsString('class="simple-form-submit-btn"', $end);
        $this->assertStringContainsString('</form>', $end);
        // The single field carries its handle.
        $this->assertStringContainsString('data-sf-handle="name"', $field);
    }

    public function testFieldCarriesConditionalDataAttr(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Cond', 'render_cond');
        $this->createField((int) $form->id, 'text', 'extra', 'Extra', false, [
            'conditional' => [
                'enabled' => true,
                'action' => 'show',
                'match' => 'all',
                'rules' => [['field' => 'name', 'operator' => 'eq', 'value' => 'x']],
            ],
        ]);
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        $field = (string) Plugin::getInstance()->getFormRender()->renderField('render_cond', 'extra');

        $this->assertStringContainsString('data-sf-handle="extra"', $field);
        $this->assertStringContainsString('data-sf-conditional=', $field);
    }

    public function testAssetsCanBeSuppressedByEmptyOverride(): void
    {
        $this->requireCraft();
        $settings = Plugin::getInstance()->getSettings();
        $original = $settings->inlineFormAssets;
        $settings->inlineFormAssets = true; // force inline so assets would normally emit

        try {
            $this->seedForm('render_noassets', '_sf-theme-assets');
            $html = Plugin::getInstance()->getFormRender()->renderForm('render_noassets');
            $this->assertStringNotContainsString('<style>', $html);
            $this->assertStringNotContainsString('<script>', $html);
        } finally {
            $settings->inlineFormAssets = $original;
        }
    }

    public function testUnknownHandleReturnsComment(): void
    {
        $this->requireCraft();
        $html = Plugin::getInstance()->getFormRender()->renderForm('nope_nope');
        $this->assertStringContainsString('not found', $html);
    }
}

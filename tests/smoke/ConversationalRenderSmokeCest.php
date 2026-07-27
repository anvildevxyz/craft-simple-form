<?php

namespace anvildev\simpleform\tests\smoke;

use Craft;
use SmokeTester;

/**
 * Conversational render mode + built-in theme smoke tests (#239/#243): a
 * conversational form renders one screen per question with the navigator + the
 * centered-card theme's progress bar, while standard mode is unaffected.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class ConversationalRenderSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testConversationalFormRendersScreensNavAndProgressBar(SmokeTester $I): void
    {
        $form = $this->threeFieldForm('convOn' . uniqid(), 'conversational');
        $html = $this->renderForm($form->handle);

        $I->assertStringContainsString('simple-form--conversational', $html);
        $I->assertStringContainsString('simple-form-step-nav', $html);
        // The #243 theme progress bar element the navigator fills.
        $I->assertStringContainsString('simple-form-progressbar', $html);
        $I->assertStringContainsString('data-sf-progressbar', $html);
    }

    public function testEachQuestionIsItsOwnScreen(SmokeTester $I): void
    {
        $form = $this->threeFieldForm('convScreens' . uniqid(), 'conversational');
        $html = $this->renderForm($form->handle);

        // Single page + three inputs → three screens (data-sf-step 0..2).
        $I->assertSame(3, substr_count($html, 'class="simple-form-step"'));
        $I->assertStringContainsString('data-sf-step="0"', $html);
        $I->assertStringContainsString('data-sf-step="2"', $html);
    }

    public function testStandardFormHasNoConversationalMarkup(SmokeTester $I): void
    {
        $form = $this->threeFieldForm('convOff' . uniqid(), 'standard');
        $html = $this->renderForm($form->handle);

        $I->assertStringNotContainsString('simple-form--conversational', $html);
        $I->assertStringNotContainsString('data-sf-progressbar', $html);
        // A single-page standard form renders no step navigator.
        $I->assertStringNotContainsString('simple-form-step-nav', $html);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function threeFieldForm(string $handle, string $renderMode): \anvildev\simpleform\elements\Form
    {
        $form = $this->createForm('Conversational', $handle);
        $this->createField((int) $form->id, 'text', 'first', 'First name');
        $this->createField((int) $form->id, 'text', 'last', 'Last name');
        $this->createField((int) $form->id, 'email', 'email', 'Email');

        $form = $this->reloadForm($form);
        $form->renderMode = $renderMode;
        Craft::$app->getElements()->saveElement($form);

        return $form;
    }
}

<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\db\FormQuery;
use fabianhaef\simpleform\elements\db\SubmissionQuery;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\web\twig\variables\SimpleFormVariable;
use Twig\Markup;

/**
 * The craft.simpleForm.* template API (#110): direct method behaviour plus the
 * craft.simpleForm wiring via a rendered Twig string.
 */
class CraftVariableTest extends SimpleFormTestCase
{
    public function testVariableMethods(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Contact', 'var_contact');

        $variable = new SimpleFormVariable();

        $byHandle = $variable->form('var_contact');
        $this->assertInstanceOf(Form::class, $byHandle);
        $this->assertSame((int) $form->id, (int) $byHandle->id);

        $byId = $variable->form((int) $form->id);
        $this->assertInstanceOf(Form::class, $byId);

        $this->assertInstanceOf(FormQuery::class, $variable->forms());
        $this->assertInstanceOf(SubmissionQuery::class, $variable->submissions());

        $markup = $variable->render('var_contact');
        $this->assertInstanceOf(Markup::class, $markup);
        $this->assertStringContainsString('<form', (string) $markup);
    }

    public function testCraftSimpleFormIsWiredInTemplates(): void
    {
        $this->requireCraft();
        $this->createForm('Contact', 'var_wired');

        $view = Craft::$app->getView();
        $handle = $view->renderString('{{ craft.simpleForm.form("var_wired").handle }}');
        $this->assertSame('var_wired', trim($handle));

        $rendered = $view->renderString('{{ craft.simpleForm.render("var_wired") }}');
        $this->assertStringContainsString('<form', $rendered);
    }
}

<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Form;
use Craft;
use SmokeTester;

/**
 * The form editor presents Title as optional (no required marker), but
 * {@see Form::hasTitles()} makes Craft reject a blank title. Form::beforeValidate()
 * defaults a blank title to the Name so saving a form without a Title succeeds,
 * matching how the editor presents it (#428).
 *
 * @author Anvil Dev
 * @since 2.17.0
 */
class FormTitleDefaultCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * A form saved with a blank title is accepted, and its title defaults to the
     * Name — both in memory and after a round-trip through the DB.
     */
    public function testBlankTitleDefaultsToName(SmokeTester $I): void
    {
        $name = 'Titleless ' . uniqid();

        $form = new Form();
        $form->name = $name;
        $form->handle = 'titleless' . uniqid();
        $form->title = null; // left blank in the editor
        $form->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $saved = Craft::$app->getElements()->saveElement($form);

        $I->assertTrue($saved, 'a form with a blank title saves without a "Title cannot be blank" error');
        $I->assertSame($name, $form->title, 'the blank title defaulted to the form name');

        $reloaded = Form::find()->id($form->id)->one();
        $I->assertInstanceOf(Form::class, $reloaded);
        $I->assertSame($name, $reloaded->title, 'the defaulted title round-trips through elements_sites');
    }

    /**
     * An explicitly-authored title is never overwritten by the Name default.
     */
    public function testExplicitTitleIsPreserved(SmokeTester $I): void
    {
        $title = 'Custom Title ' . uniqid();

        $form = new Form();
        $form->name = 'Named ' . uniqid();
        $form->handle = 'named' . uniqid();
        $form->title = $title;
        $form->siteId = Craft::$app->getSites()->getPrimarySite()->id;

        $I->assertTrue(Craft::$app->getElements()->saveElement($form), 'the form saves');
        $I->assertSame($title, Form::find()->id($form->id)->one()->title, 'the explicit title is kept, not replaced by the name');
    }
}

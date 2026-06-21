<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\enums\PropagationMethod;
use craft\models\Site;
use craft\models\SiteGroup;
use fabianhaef\simpleform\elements\Form;

/**
 * Storage round-trip for the post-submit columns (#133): the 5 new properties
 * save and read back, defaults are correct for untouched forms, and a
 * propagating save never clobbers a sibling site's translated message/URL.
 *
 * @group requires-craft
 */
class PostSubmitFormTest extends SimpleFormTestCase
{
    public function testDefaultsForUntouchedForm(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Defaults', 'postsubmit_defaults');
        $reloaded = Form::find()->id($form->id)->one();

        $this->assertSame('message', $reloaded->postSubmitAction);
        $this->assertNull($reloaded->redirectEntryId);
        $this->assertNull($reloaded->submitMessage);
        $this->assertNull($reloaded->errorMessage);
        $this->assertNull($reloaded->redirectUrl);
    }

    public function testAllPropertiesRoundTrip(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Full', 'postsubmit_full');
        $form->postSubmitAction = 'url';
        $form->redirectEntryId = 42;
        $form->submitMessage = 'Thanks {firstName}!';
        $form->errorMessage = 'Oops, try again.';
        $form->redirectUrl = '/thanks?e={email}';
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertSame('url', $reloaded->postSubmitAction);
        $this->assertSame(42, $reloaded->redirectEntryId);
        $this->assertSame('Thanks {firstName}!', $reloaded->submitMessage);
        $this->assertSame('Oops, try again.', $reloaded->errorMessage);
        $this->assertSame('/thanks?e={email}', $reloaded->redirectUrl);
    }

    public function testPropagationDoesNotClobberSiblingSiteMessage(): void
    {
        $this->requireCraft();

        $sites = Craft::$app->getSites();
        $primary = $sites->getPrimarySite();
        $secondSite = $this->createSecondSite();

        $form = new Form();
        $form->name = 'Localized PostSubmit';
        $form->handle = 'postsubmit_localized';
        $form->title = 'Contact';
        $form->submitMessage = 'Thanks (EN)';
        $form->redirectUrl = '/en/thanks';
        $form->propagationMethod = PropagationMethod::All;
        $form->siteId = $primary->id;
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));

        // Author a distinct message/URL for the second site only.
        $deForm = Form::find()->id($form->id)->siteId($secondSite->id)->one();
        $deForm->siteId = $secondSite->id;
        $deForm->submitMessage = 'Danke (DE)';
        $deForm->redirectUrl = '/de/danke';
        $this->assertTrue(Craft::$app->getElements()->saveElement($deForm));

        $enForm = Form::find()->id($form->id)->siteId($primary->id)->one();
        $deReloaded = Form::find()->id($form->id)->siteId($secondSite->id)->one();

        $this->assertSame('Thanks (EN)', $enForm->submitMessage);
        $this->assertSame('/en/thanks', $enForm->redirectUrl);
        $this->assertSame('Danke (DE)', $deReloaded->submitMessage);
        $this->assertSame('/de/danke', $deReloaded->redirectUrl);
    }

    private function createSecondSite(): Site
    {
        $sitesService = Craft::$app->getSites();

        foreach ($sitesService->getAllSites() as $existing) {
            if ($existing->handle === 'integrationSecondSite') {
                return $existing;
            }
        }

        $primary = $sitesService->getPrimarySite();

        $site = new Site([
            'groupId' => $primary->groupId ?? $this->firstGroupId(),
            'name' => 'Integration Second Site',
            'handle' => 'integrationSecondSite',
            'language' => 'de',
            'hasUrls' => false,
            'primary' => false,
        ]);

        $this->assertTrue(
            $sitesService->saveSite($site),
            'Second site should save: ' . implode(', ', $site->getFirstErrors()),
        );

        return $site;
    }

    private function firstGroupId(): int
    {
        $groups = Craft::$app->getSites()->getAllGroups();
        if (!empty($groups)) {
            return (int) $groups[0]->id;
        }

        $group = new SiteGroup(['name' => 'Test Group']);
        Craft::$app->getSites()->saveGroup($group);
        return (int) $group->id;
    }
}

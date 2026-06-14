<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\enums\PropagationMethod;
use craft\models\Site;
use craft\models\SiteGroup;
use fabianhaef\simpleform\elements\Form;

/**
 * A localized Form propagates across sites; per-site content (title/description)
 * must resolve independently per site. Creates a second site, saves distinct
 * content for it, and asserts the element query returns the right content for
 * each site.
 *
 * @group requires-craft
 */
class MultiSiteFormTest extends SimpleFormTestCase
{
    public function testPerSiteContentResolvesIndependently(): void
    {
        $this->requireCraft();

        $sites = Craft::$app->getSites();
        $primary = $sites->getPrimarySite();
        $secondSite = $this->createSecondSite();

        // Save on the primary site, propagating to all sites so a seed row exists
        // in simpleform_forms_sites for the second site too.
        $form = new Form();
        $form->name = 'Localized';
        $form->handle = 'localizedForm';
        $form->title = 'Contact';
        $form->description = 'English description';
        $form->emailTo = 'en@example.com';
        $form->propagationMethod = PropagationMethod::All;
        $form->siteId = $primary->id;

        $this->assertTrue(
            Craft::$app->getElements()->saveElement($form),
            'Form should save: ' . implode(', ', $form->getFirstErrors()),
        );

        // Edit the second site's content: reload the element in that site context,
        // change the translatable fields, and save (canonical save for that site).
        $secondSiteForm = Form::find()->id($form->id)->siteId($secondSite->id)->one();
        $this->assertNotNull($secondSiteForm, 'Form should be available on the second site');

        // Edit the plugin's per-site (simpleform_forms_sites) translatable content
        // for the second site only. afterSave() writes the row for $this->siteId and
        // guards sibling rows against propagation, so the primary site's values must be
        // preserved. Pin the siteId explicitly: a form loaded for a freshly-created site
        // can fall back to the primary site's element row.
        $secondSiteForm->siteId = $secondSite->id;
        $secondSiteForm->description = 'Deutsche Beschreibung';
        $secondSiteForm->emailTo = 'de@example.com';
        $secondSiteForm->emailSubject = 'Neue Einsendung';
        $this->assertTrue(Craft::$app->getElements()->saveElement($secondSiteForm));

        // Query from each site — the plugin's per-site content must resolve independently.
        $enForm = Form::find()->id($form->id)->siteId($primary->id)->one();
        $deForm = Form::find()->id($form->id)->siteId($secondSite->id)->one();

        $this->assertNotNull($enForm);
        $this->assertNotNull($deForm);

        $this->assertSame('English description', $enForm->description);
        $this->assertSame('en@example.com', $enForm->emailTo);

        $this->assertSame('Deutsche Beschreibung', $deForm->description);
        $this->assertSame('de@example.com', $deForm->emailTo);
        $this->assertSame('Neue Einsendung', $deForm->emailSubject);

        // The shared handle/name are identical across sites (single shared row).
        $this->assertSame('localizedForm', $enForm->handle);
        $this->assertSame('localizedForm', $deForm->handle);
        $this->assertSame('Localized', $enForm->name);
        $this->assertSame('Localized', $deForm->name);
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

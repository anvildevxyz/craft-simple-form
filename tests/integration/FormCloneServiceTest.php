<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\enums\PropagationMethod;
use craft\models\Site;
use craft\models\SiteGroup;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\models\NotificationModel;
use fabianhaef\simpleform\Plugin;

/**
 * Deep-copy of a form (#138): duplicate produces an independent form with new
 * field ids, a unique handle, copied notifications + integration attachments,
 * zero submissions, and retargeted conditional logic. Stencils instantiate the
 * expected field set.
 */
class FormCloneServiceTest extends SimpleFormTestCase
{
    public function testDuplicateCopiesFieldsWithNewIdsAndUniqueHandle(): void
    {
        $this->requireCraft();
        $source = $this->createForm('Support', 'support', 'Support Request');
        $textId = $this->createField((int) $source->id, 'text', 'name', 'Name', true);
        $emailId = $this->createField((int) $source->id, 'email', 'email', 'Email', true);

        $copy = Plugin::getInstance()->getFormClone()->duplicate($source);

        $this->assertNotSame((int) $source->id, (int) $copy->id, 'copy is a new element');
        $this->assertSame('support-copy', $copy->handle, 'copy gets a -copy handle');
        $this->assertNotSame($source->handle, $copy->handle);

        $copyFields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $copy->id);
        $this->assertCount(2, $copyFields);

        $copyIds = array_map(static fn($f) => (int) $f['id'], $copyFields);
        $this->assertNotContains($textId, $copyIds, 'copied fields get new ids');
        $this->assertNotContains($emailId, $copyIds);

        // Handles and types carry verbatim.
        $byHandle = [];
        foreach ($copyFields as $f) {
            $byHandle[$f['name']] = $f;
        }
        $this->assertSame('text', $byHandle['name']['type']);
        $this->assertSame('email', $byHandle['email']['type']);
        $this->assertTrue((bool) $byHandle['name']['required']);
    }

    public function testDuplicateLeavesSourceSubmissionsUntouchedAndCopyEmpty(): void
    {
        $this->requireCraft();
        $source = $this->createForm('Newsletter', 'newsletter', 'Newsletter');
        $this->createField((int) $source->id, 'email', 'email', 'Email', true);

        $submission = new Submission();
        $submission->formId = (int) $source->id;
        $submission->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $this->assertTrue(Craft::$app->getElements()->saveElement($submission));

        $copy = Plugin::getInstance()->getFormClone()->duplicate($source);

        $sourceCount = (int) Submission::find()->formId((int) $source->id)->status(null)->count();
        $copyCount = (int) Submission::find()->formId((int) $copy->id)->status(null)->count();
        $this->assertSame(1, $sourceCount, 'source keeps its submission');
        $this->assertSame(0, $copyCount, 'copy has zero submissions');
    }

    public function testDuplicateCopiesNotificationsWithNewFormId(): void
    {
        $this->requireCraft();
        $source = $this->createForm('Contact', 'contact', 'Contact');
        $this->createField((int) $source->id, 'email', 'email', 'Email', true);

        $notification = new NotificationModel();
        $notification->formId = (int) $source->id;
        $notification->name = 'Admin alert';
        $notification->recipient = 'ops@example.test';
        $notification->subject = 'New contact';
        $this->assertTrue(Plugin::getInstance()->getNotifications()->save($notification));

        $copy = Plugin::getInstance()->getFormClone()->duplicate($source);

        $copied = Plugin::getInstance()->getNotifications()->getForForm((int) $copy->id);
        $this->assertCount(1, $copied);
        $this->assertSame((int) $copy->id, $copied[0]->formId);
        $this->assertSame('ops@example.test', $copied[0]->recipient);
        $this->assertNotSame($notification->id, $copied[0]->id, 'copied notification gets a new id');
        $this->assertNotSame($notification->uid, $copied[0]->uid, 'copied notification gets a new uid');

        // Source notification is untouched.
        $original = Plugin::getInstance()->getNotifications()->getForForm((int) $source->id);
        $this->assertCount(1, $original);
        $this->assertSame((int) $source->id, $original[0]->formId);
    }

    public function testDuplicateCopiesIntegrationAttachmentsWithoutCloningTheIntegration(): void
    {
        $this->requireCraft();
        $source = $this->createForm('Lead', 'lead', 'Lead');
        $this->createField((int) $source->id, 'email', 'email', 'Email', true);

        $integration = new \fabianhaef\simpleform\models\IntegrationModel();
        $integration->type = 'webhook';
        $integration->name = 'Hook';
        $integration->settings = ['url' => 'https://example.test/hook'];
        $this->assertTrue(Plugin::getInstance()->getIntegrations()->saveIntegration($integration));
        Plugin::getInstance()->getIntegrations()->toggleFormIntegration((int) $source->id, (int) $integration->id);

        $copy = Plugin::getInstance()->getFormClone()->duplicate($source);

        $copyIds = Plugin::getInstance()->getIntegrations()->getAttachedIntegrationIds((int) $copy->id);
        $this->assertSame([(int) $integration->id], $copyIds, 'copy references the same global integration');

        // Only one integration row exists — the definition was not cloned.
        $defCount = (int) (new Query())->from('{{%simpleform_integrations}}')->where(['id' => $integration->id])->count();
        $this->assertSame(1, $defCount);
    }

    public function testDuplicateRetargetsConditionalLogicToTheCopysFields(): void
    {
        $this->requireCraft();
        $source = $this->createForm('Cond', 'cond', 'Cond');
        $this->createField((int) $source->id, 'select', 'accountType', 'Account type', false, [
            'options' => [['value' => 'business', 'label' => 'Business']],
        ]);
        // A field whose visibility depends on accountType.
        $this->createField((int) $source->id, 'text', 'vat', 'VAT', false, [
            'conditional' => [
                'enabled' => true,
                'action' => 'show',
                'match' => 'all',
                'rules' => [['field' => 'accountType', 'operator' => 'eq', 'value' => 'business']],
            ],
        ]);

        $copy = Plugin::getInstance()->getFormClone()->duplicate($source);

        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $copy->id);
        $vat = null;
        foreach ($fields as $f) {
            if ($f['name'] === 'vat') {
                $vat = $f;
            }
        }
        $this->assertNotNull($vat);
        // The rule still references the handle (which is present on the copy).
        $this->assertSame('accountType', $vat['config']['conditional']['rules'][0]['field']);
    }

    public function testUniqueHandleAppendsCopyThenNumberedSuffix(): void
    {
        $this->requireCraft();
        $service = Plugin::getInstance()->getFormClone();

        $this->createForm('A', 'widget', 'A');
        $this->assertSame('widget-copy', $service->uniqueHandle('widget'));

        $this->createForm('B', 'widget-copy', 'B');
        $this->assertSame('widget-copy-2', $service->uniqueHandle('widget'));
        // Re-duplicating the copy strips the suffix before appending.
        $this->assertSame('widget-copy-2', $service->uniqueHandle('widget-copy'));

        $this->createForm('C', 'widget-copy-2', 'C');
        $this->assertSame('widget-copy-3', $service->uniqueHandle('widget'));

        // A free base is returned unchanged.
        $this->assertSame('fresh', $service->uniqueHandle('fresh'));
    }

    public function testCreateFromContactStencilSeedsExpectedFieldsAndNotification(): void
    {
        $this->requireCraft();
        $stencil = Plugin::getInstance()->getStencilLibrary()->getByHandle('contact');
        $this->assertNotNull($stencil);

        $form = Plugin::getInstance()->getFormClone()->createFromStencil($stencil);

        $this->assertNotNull($form->id);
        $this->assertSame('contact', $form->handle);

        $fields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $form->id);
        $handles = array_map(static fn($f) => $f['name'], $fields);
        $this->assertSame(['name', 'email', 'message'], $handles);

        $types = array_map(static fn($f) => $f['type'], $fields);
        $this->assertSame(['text', 'email', 'textarea'], $types);
    }

    public function testStencilHandleCollisionResolvesToADistinctHandle(): void
    {
        $this->requireCraft();
        $library = Plugin::getInstance()->getStencilLibrary();
        $stencil = $library->getByHandle('newsletter');
        $this->assertNotNull($stencil);

        $first = Plugin::getInstance()->getFormClone()->createFromStencil($stencil);
        $second = Plugin::getInstance()->getFormClone()->createFromStencil($stencil);

        $this->assertSame('newsletter', $first->handle);
        $this->assertSame('newsletter-copy', $second->handle);
        $this->assertNotSame($first->id, $second->id);
    }

    public function testDuplicateCarriesEverySitesContentAndFieldLabels(): void
    {
        $this->requireCraft();
        $primary = Craft::$app->getSites()->getPrimarySite();
        $second = $this->createSecondSite();

        // A form propagated to all sites, with per-site translated email content
        // and a select field whose option label is translated on the second site.
        $form = new Form();
        $form->siteId = $primary->id;
        $form->name = 'Quote';
        $form->handle = 'quote';
        $form->title = 'Quote';
        $form->emailSubject = 'New quote';
        $form->propagationMethod = PropagationMethod::All;
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));

        $fieldId = $this->createField((int) $form->id, 'select', 'plan', 'Plan', false, [
            'options' => [['value' => 'pro', 'label' => 'Pro']],
        ]);

        // Translate the email subject + the option label on the second site.
        $deForm = Form::find()->id($form->id)->siteId($second->id)->status(null)->one();
        $this->assertNotNull($deForm);
        $deForm->siteId = $second->id;
        $deForm->emailSubject = 'Neues Angebot';
        $this->assertTrue(
            Craft::$app->getElements()->saveElement($deForm),
            'de form save: ' . implode(', ', $deForm->getFirstErrors()),
        );

        Craft::$app->getDb()->createCommand()->update(
            '{{%simpleform_fields_sites}}',
            ['optionLabels' => ['pro' => 'Profi']],
            ['fieldId' => $fieldId, 'siteId' => $second->id],
        )->execute();

        $copy = Plugin::getInstance()->getFormClone()->duplicate($form);

        // The primary site's content is carried.
        $enCopy = Form::find()->id($copy->id)->siteId($primary->id)->status(null)->one();
        $this->assertNotNull($enCopy);
        $this->assertSame('New quote', $enCopy->emailSubject);

        // The second site's translated email content is carried.
        $deCopy = Form::find()->id($copy->id)->siteId($second->id)->status(null)->one();
        $this->assertNotNull($deCopy);
        $this->assertSame('Neues Angebot', $deCopy->emailSubject);

        // The second site's translated option label is carried onto the copy's
        // new field (matched by handle, new id).
        $deFields = Plugin::getInstance()->getFormStructure()->getFieldSet((int) $copy->id, (int) $second->id);
        $this->assertCount(1, $deFields);
        $this->assertSame('Profi', $deFields[0]['optionLabels']['pro'] ?? null);
        $this->assertNotSame($fieldId, (int) $deFields[0]['id'], 'field gets a new id');
    }

    public function testEveryBuiltInStencilIsInstantiable(): void
    {
        $this->requireCraft();
        $library = Plugin::getInstance()->getStencilLibrary();
        $handles = array_keys($library->getAll());
        $this->assertContains('contact', $handles);
        $this->assertContains('newsletter', $handles);
        $this->assertContains('event-registration', $handles);
        $this->assertContains('support-request', $handles);

        $validTypes = Plugin::getInstance()->getFieldTypeRegistry()->typeHandles();
        foreach ($library->getAll() as $stencil) {
            $form = Plugin::getInstance()->getFormClone()->createFromStencil($stencil);
            $this->assertNotNull($form->id, "Stencil {$stencil->handle} should instantiate");
            foreach ($stencil->fields as $field) {
                $this->assertContains($field['type'], $validTypes, "Stencil {$stencil->handle} uses a known field type");
            }
        }
    }

    private function createSecondSite(): Site
    {
        $sitesService = Craft::$app->getSites();

        foreach ($sitesService->getAllSites() as $existing) {
            if ($existing->handle === 'cloneSecondSite') {
                return $existing;
            }
        }

        $primary = $sitesService->getPrimarySite();
        $site = new Site([
            'groupId' => $primary->groupId ?? $this->firstGroupId(),
            'name' => 'Clone Second Site',
            'handle' => 'cloneSecondSite',
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

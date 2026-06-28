<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\integrations\CraftElementIntegration;
use anvildev\simpleform\integrations\DispatchStatus;
use anvildev\simpleform\models\IntegrationModel;
use anvildev\simpleform\Plugin;
use Craft;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\EntryType;
use craft\models\Section;
use craft\models\Section_SiteSettings;

/**
 * End-to-end coverage for the element connector: a mapped submission becomes an
 * Entry / User, validation failures land as failed (retryable) dispatch-log rows
 * while the submission survives, and a resend reuses the existing element.
 *
 * @group requires-craft
 */
class CraftElementIntegrationTest extends SimpleFormTestCase
{
    public function testSettingsHtmlRenders(): void
    {
        $this->requireCraft();
        $html = (new CraftElementIntegration())->settingsHtml(['elementType' => 'entry']);
        $this->assertStringContainsString('Field mapping', $html);
        $this->assertStringContainsString('User group', $html);
        $this->assertStringContainsString('Title template', $html);
    }

    /** Create a channel section with one entry type for the current site. */
    private function makeSection(string $handle): Section
    {
        $entryType = new EntryType();
        $entryType->name = ucfirst($handle);
        $entryType->handle = $handle . 'Type';
        $entryType->hasTitleField = true;
        $entryType->titleFormat = null;

        // A field layout whose first tab carries the native Title field element, so
        // entries in this type actually have a title column to store.
        $layout = new \craft\models\FieldLayout(['type' => Entry::class]);
        $tab = new \craft\models\FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new \craft\fieldlayoutelements\entries\EntryTitleField()]);
        $layout->setTabs([$tab]);
        $entryType->setFieldLayout($layout);

        $this->assertTrue(Craft::$app->getEntries()->saveEntryType($entryType), implode(',', $entryType->getFirstErrors()));

        $site = Craft::$app->getSites()->getPrimarySite();
        $section = new Section();
        $section->name = ucfirst($handle);
        $section->handle = $handle;
        $section->type = Section::TYPE_CHANNEL;
        $section->setSiteSettings([
            $site->id => new Section_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => false,
            ]),
        ]);
        $section->setEntryTypes([$entryType]);
        $this->assertTrue(Craft::$app->getEntries()->saveSection($section), 'section saves');

        return $section;
    }

    private function makeSubmission(int $formId, array $data = []): Submission
    {
        $sub = new Submission();
        $sub->formId = $formId;
        $sub->siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $sub->data = $data;
        $sub->readStatus = 'new';
        $this->assertTrue(Craft::$app->getElements()->saveElement($sub));
        return $sub;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function makeIntegration(int $formId, array $settings): IntegrationModel
    {
        $service = Plugin::getInstance()->getIntegrations();
        $m = new IntegrationModel();
        $m->type = CraftElementIntegration::handle();
        $m->name = 'Element';
        $m->enabled = true;
        $m->settings = $settings;
        $this->assertTrue($service->saveIntegration($m));
        $service->toggleFormIntegration($formId, (int) $m->id);
        return $m;
    }

    public function testCreatesEntryWithMappedTitle(): void
    {
        $this->requireCraft();
        $section = $this->makeSection('sfEvents');
        $entryType = $section->getEntryTypes()[0];

        $form = $this->createForm('Event Form', 'event_form');
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $integration = $this->makeIntegration((int) $form->id, [
            'elementType' => 'entry',
            'sectionUid' => $section->uid,
            'entryTypeUid' => $entryType->uid,
            'titleTemplate' => '{{ values.name }} event',
            'entryStatus' => 'pending',
            'fieldMapping' => [['source' => 'name', 'target' => 'slug']],
        ]);

        $sub = $this->makeSubmission((int) $form->id, [
            'field_' . $fieldId => ['label' => 'Name', 'type' => 'text', 'value' => 'Launch'],
        ]);

        $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $sub);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(Entry::class, $result->elementType);
        $this->assertNotNull($result->elementId);

        $entry = Entry::find()->id($result->elementId)->siteId($sub->siteId)->status(null)->one();
        $this->assertNotNull($entry);
        // Title comes from the rendered template; the native `slug` attribute from
        // the field mapping.
        $this->assertSame('Launch event', (string) $entry->title);
        $this->assertSame('launch', (string) $entry->slug);
        $this->assertSame($sub->siteId, $entry->siteId);
    }

    public function testCreatesUserInGroup(): void
    {
        $this->requireCraft();
        $group = new \craft\models\UserGroup(['name' => 'Members', 'handle' => 'sfMembers' . substr(uniqid(), -5)]);
        $this->assertTrue(Craft::$app->getUserGroups()->saveGroup($group), implode(',', $group->getFirstErrors()));

        $form = $this->createForm('Reg Form', 'reg_form');
        $emailFieldId = $this->createField((int) $form->id, 'email', 'email', 'Email');

        $integration = $this->makeIntegration((int) $form->id, [
            'elementType' => 'user',
            'groupUid' => $group->uid,
            'userStatus' => 'active',
            'fieldMapping' => [['source' => 'email', 'target' => 'email']],
        ]);

        $email = 'sf-test-' . uniqid() . '@example.test';
        $sub = $this->makeSubmission((int) $form->id, [
            'field_' . $emailFieldId => ['label' => 'Email', 'type' => 'email', 'value' => $email],
        ]);

        $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $sub);
        $this->assertTrue($result->success, $result->message);
        $this->assertSame(User::class, $result->elementType);

        $user = User::find()->id($result->elementId)->status(null)->one();
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame($email, $user->email);
        $this->assertTrue($user->isInGroup($group->id));
    }

    public function testValidationFailureLogsFailedDispatchAndKeepsSubmission(): void
    {
        $this->requireCraft();
        $group = new \craft\models\UserGroup(['name' => 'Members', 'handle' => 'sfBad' . substr(uniqid(), -5)]);
        $this->assertTrue(Craft::$app->getUserGroups()->saveGroup($group), implode(',', $group->getFirstErrors()));

        $form = $this->createForm('Bad Reg Form', 'bad_reg_form');
        $emailFieldId = $this->createField((int) $form->id, 'text', 'email', 'Email');

        $integration = $this->makeIntegration((int) $form->id, [
            'elementType' => 'user',
            'groupUid' => $group->uid,
            'userStatus' => 'active',
            'fieldMapping' => [['source' => 'email', 'target' => 'email']],
        ]);

        // An invalid email address fails Craft's User validation — the dispatch
        // must fail (retryable) while the submission row is still saved.
        $sub = $this->makeSubmission((int) $form->id, [
            'field_' . $emailFieldId => ['label' => 'Email', 'type' => 'text', 'value' => 'not-an-email'],
        ]);

        $result = Plugin::getInstance()->getIntegrations()->runOnce($integration, $sub);

        $this->assertFalse($result->success, 'msg=' . $result->message);
        $this->assertNotSame('', $result->message);
        $this->assertNull($result->elementId);
        $this->assertNotNull(Submission::find()->id($sub->id)->one(), 'submission still saved on failure');

        $logs = (new \craft\db\Query())
            ->from('{{%simpleform_integration_logs}}')
            ->where(['submissionId' => $sub->id])
            ->all();
        $this->assertCount(1, $logs);
        $this->assertSame(DispatchStatus::FAILED, $logs[0]['status']);
    }

    public function testResendDoesNotDuplicate(): void
    {
        $this->requireCraft();
        $section = $this->makeSection('sfComments');
        $entryType = $section->getEntryTypes()[0];

        $form = $this->createForm('Comment Form', 'comment_form');
        $fieldId = $this->createField((int) $form->id, 'text', 'body', 'Body');

        $integration = $this->makeIntegration((int) $form->id, [
            'elementType' => 'entry',
            'sectionUid' => $section->uid,
            'entryTypeUid' => $entryType->uid,
            'titleTemplate' => 'Comment',
            'fieldMapping' => [['source' => 'body', 'target' => 'title']],
        ]);

        $sub = $this->makeSubmission((int) $form->id, [
            'field_' . $fieldId => ['label' => 'Body', 'type' => 'text', 'value' => 'Hi'],
        ]);

        $service = Plugin::getInstance()->getIntegrations();
        $first = $service->runOnce($integration, $sub);
        $this->assertTrue($first->success);
        $createdId = $first->elementId;

        // Resend: same integration + submission. The connector must link the
        // existing element rather than create a new one.
        $second = $service->runOnce($integration, $sub);
        $this->assertTrue($second->success);
        $this->assertSame($createdId, $second->elementId);

        $count = Entry::find()
            ->sectionId($section->id)
            ->siteId('*')
            ->status(null)
            ->count();
        $this->assertSame(1, (int) $count, 'resend must not create a duplicate entry');
    }
}

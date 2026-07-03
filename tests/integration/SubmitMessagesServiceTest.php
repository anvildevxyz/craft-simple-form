<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\Editions;
use anvildev\simpleform\models\SubmitMessageModel;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmitMessagesService;
use Craft;
use craft\db\Query;

/**
 * Conditional submit messages (#265) — the two-table write path
 * ({@see \anvildev\simpleform\services\SubmitMessagesService}): structural row +
 * per-site message rows, sort ordering, reorder, delete cascade, and the
 * Pro-edition gate on creating new rows.
 *
 * @group requires-craft
 */
class SubmitMessagesServiceTest extends SimpleFormTestCase
{
    private ?string $originalEdition = null;

    protected function tearDown(): void
    {
        if ($this->originalEdition !== null) {
            Plugin::getInstance()->edition = $this->originalEdition;
            $this->originalEdition = null;
        }
        parent::tearDown();
    }

    private function service(): SubmitMessagesService
    {
        return Plugin::getInstance()->getSubmitMessages();
    }

    private function setSolo(): void
    {
        $plugin = Plugin::getInstance();
        $this->originalEdition ??= $plugin->edition;
        $plugin->edition = Editions::SOLO;
    }

    /**
     * @param array<string, mixed>|null $conditional
     * @param array<int, string> $messages
     */
    private function save(int $formId, ?array $conditional, array $messages, ?int $sortOrder = null): SubmitMessageModel
    {
        $model = new SubmitMessageModel();
        $model->formId = $formId;
        $model->conditional = $conditional;
        $model->messages = $messages;
        $model->sortOrder = $sortOrder;
        $this->assertTrue($this->service()->save($model), implode(', ', $model->getFirstErrors()));
        return $model;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function structuralRow(int $id): ?array
    {
        $row = (new Query())->from('{{%simpleform_submit_messages}}')->where(['id' => $id])->one();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function siteRow(int $submitMessageId, int $siteId): ?array
    {
        $row = (new Query())->from('{{%simpleform_submit_messages_sites}}')
            ->where(['submitMessageId' => $submitMessageId, 'siteId' => $siteId])->one();
        return is_array($row) ? $row : null;
    }

    public function testSaveWritesStructuralAndPerSiteRows(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SM Add', 'sm_add');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $conditional = [
            'enabled' => true,
            'match' => 'all',
            'action' => 'show',
            'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'sales']],
        ];

        $model = $this->save((int) $form->id, $conditional, [$siteId => 'A specialist will call you.']);

        $structural = $this->structuralRow((int) $model->id);
        $this->assertNotNull($structural);
        $this->assertSame((int) $form->id, (int) $structural['formId']);
        $this->assertSame(1, (int) $structural['sortOrder']);
        $this->assertNotNull($structural['uid']);

        $site = $this->siteRow((int) $model->id, $siteId);
        $this->assertNotNull($site);
        $this->assertSame('A specialist will call you.', $site['message']);
    }

    public function testSaveAssignsIncrementingSortOrder(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SM Sort', 'sm_sort');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $first = $this->save((int) $form->id, null, [$siteId => 'One']);
        $second = $this->save((int) $form->id, null, [$siteId => 'Two']);

        $this->assertSame(1, $first->sortOrder);
        $this->assertSame(2, $second->sortOrder);
    }

    public function testUpdateChangesMessageAndConditionInPlace(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SM Update', 'sm_update');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $model = $this->save((int) $form->id, null, [$siteId => 'Old']);
        $id = (int) $model->id;

        $model->conditional = ['enabled' => true, 'match' => 'any', 'rules' => [['field' => 'x', 'operator' => 'notEmpty', 'value' => '']]];
        $model->messages = [$siteId => 'New'];
        $this->assertTrue($this->service()->save($model));

        $reloaded = $this->service()->getById($id);
        $this->assertNotNull($reloaded);
        $this->assertSame('any', $reloaded->conditional['match']);
        $this->assertSame('New', $reloaded->messages[$siteId]);

        // No duplicate structural or per-site row was created.
        $this->assertSame(1, (int) (new Query())->from('{{%simpleform_submit_messages}}')->where(['formId' => (int) $form->id])->count());
        $this->assertSame(1, (int) (new Query())->from('{{%simpleform_submit_messages_sites}}')->where(['submitMessageId' => $id])->count());
    }

    public function testDeleteCascadesPerSiteRows(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SM Delete', 'sm_delete');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $model = $this->save((int) $form->id, null, [$siteId => 'Bye']);
        $id = (int) $model->id;

        $this->assertTrue($this->service()->delete($id));
        $this->assertNull($this->structuralRow($id));
        $this->assertNull($this->siteRow($id, $siteId));
    }

    public function testReorderRewritesSortOrderWithinForm(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SM Reorder', 'sm_reorder');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $a = $this->save((int) $form->id, null, [$siteId => 'A']);
        $b = $this->save((int) $form->id, null, [$siteId => 'B']);
        $c = $this->save((int) $form->id, null, [$siteId => 'C']);

        $this->service()->reorder([(int) $c->id, (int) $a->id, (int) $b->id], (int) $form->id);

        $ordered = array_map(static fn($m): int => (int) $m->id, $this->service()->getForForm((int) $form->id));
        $this->assertSame([(int) $c->id, (int) $a->id, (int) $b->id], $ordered);
    }

    public function testMultiSiteMessagesResolvePerSite(): void
    {
        $this->requireCraft();

        $sites = Craft::$app->getSites()->getAllSites();
        if (count($sites) < 2) {
            $this->markTestSkipped('Multi-site resolution needs at least two sites.');
        }
        $primary = (int) $sites[0]->id;
        $secondary = (int) $sites[1]->id;

        $form = $this->createForm('SM Multi', 'sm_multi');
        $this->save((int) $form->id, null, [$primary => 'Message EN', $secondary => 'Message FR']);

        $onPrimary = $this->service()->getForFormAndSite((int) $form->id, $primary);
        $onSecondary = $this->service()->getForFormAndSite((int) $form->id, $secondary);

        $this->assertSame('Message EN', $onPrimary[0]->message);
        $this->assertSame('Message FR', $onSecondary[0]->message);

        // A row translated for the primary site only resolves to null on the
        // secondary site — never another site's text.
        $onlyPrimary = $this->save((int) $form->id, null, [$primary => 'Only EN']);
        $rows = $this->service()->getForFormAndSite((int) $form->id, $secondary);
        $match = array_values(array_filter($rows, static fn($r): bool => (int) $r->id === (int) $onlyPrimary->id));
        $this->assertNull($match[0]->message);
    }

    public function testSoloCannotCreateANewRowButKeepsEditingExisting(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SM Gate', 'sm_gate');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        // A first row created while effectively Pro.
        $existing = $this->save((int) $form->id, null, [$siteId => 'Existing']);

        $this->setSolo();

        // A second (new) row is rejected on Solo — the count can't escalate.
        $second = new SubmitMessageModel();
        $second->formId = (int) $form->id;
        $second->messages = [$siteId => 'Second'];
        $this->assertFalse($this->service()->save($second));
        $this->assertArrayHasKey('conditional', $second->getErrors());
        $this->assertSame(1, (int) (new Query())->from('{{%simpleform_submit_messages}}')->where(['formId' => (int) $form->id])->count());

        // ...but the already-stored row stays editable so a downgraded site is not stuck.
        $existing->messages = [$siteId => 'Edited on Solo'];
        $this->assertTrue($this->service()->save($existing));
        $this->assertSame('Edited on Solo', $this->siteRow((int) $existing->id, $siteId)['message']);
    }
}

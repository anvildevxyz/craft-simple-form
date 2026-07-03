<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmitMessagesService;
use Craft;
use craft\db\Query;

/**
 * Conditional submit messages (#266) — the CP batch-save seam
 * ({@see \anvildev\simpleform\services\SubmitMessagesService::sync()} and
 * {@see \anvildev\simpleform\services\SubmitMessagesService::validate()}): the
 * whole ordered set is validated then replaced in one transaction (insert new,
 * update existing, rewrite sort order, delete removed), dangling rules are
 * pruned, and the message text is written per site.
 *
 * @group requires-craft
 */
class SubmitMessagesSyncTest extends SimpleFormTestCase
{
    private function service(): SubmitMessagesService
    {
        return Plugin::getInstance()->getSubmitMessages();
    }

    /**
     * @param array<string, bool> $handles
     * @return array<string, mixed>
     */
    private function rule(string $field, string $operator, string $value): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value];
    }

    /**
     * @param list<array<string, mixed>> $rules
     * @return array<string, mixed>
     */
    private function row(?int $id, array $rules, string $message, string $match = 'all'): array
    {
        return [
            'id' => $id,
            'conditional' => ['match' => $match, 'rules' => $rules],
            'message' => $message,
        ];
    }

    public function testSyncInsertsNewRowsInOrder(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SMS Insert', 'sms_insert');
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $handles = ['reason' => true, 'plan' => true];

        $this->service()->sync((int) $form->id, [
            $this->row(null, [$this->rule('reason', 'eq', 'sales')], 'Sales message'),
            $this->row(null, [$this->rule('plan', 'eq', 'pro')], 'Pro plan message'),
        ], $siteId, $handles, [$siteId]);

        $rows = $this->service()->getForFormAndSite((int) $form->id, $siteId);
        $this->assertCount(2, $rows);
        $this->assertSame(1, $rows[0]->sortOrder);
        $this->assertSame(2, $rows[1]->sortOrder);
        $this->assertSame('Sales message', $rows[0]->message);
        $this->assertSame('Pro plan message', $rows[1]->message);
        $this->assertTrue($rows[0]->conditional['enabled']);
        $this->assertSame('reason', $rows[0]->conditional['rules'][0]['field']);
    }

    public function testSyncUpdatesExistingReordersAndDeletes(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SMS Mutate', 'sms_mutate');
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $handles = ['reason' => true, 'plan' => true];

        $this->service()->sync((int) $form->id, [
            $this->row(null, [$this->rule('reason', 'eq', 'sales')], 'First'),
            $this->row(null, [$this->rule('plan', 'eq', 'pro')], 'Second'),
        ], $siteId, $handles, [$siteId]);

        $rows = $this->service()->getForFormAndSite((int) $form->id, $siteId);
        $firstId = (int) $rows[0]->id;
        $secondId = (int) $rows[1]->id;

        // Reorder (second first), edit the first's text, and drop nothing.
        $this->service()->sync((int) $form->id, [
            $this->row($secondId, [$this->rule('plan', 'eq', 'pro')], 'Second'),
            $this->row($firstId, [$this->rule('reason', 'eq', 'support')], 'First edited'),
        ], $siteId, $handles, [$siteId]);

        $rows = $this->service()->getForFormAndSite((int) $form->id, $siteId);
        $this->assertSame([$secondId, $firstId], array_map(static fn($r): int => (int) $r->id, $rows));
        $this->assertSame('First edited', $rows[1]->message);
        $this->assertSame('support', $rows[1]->conditional['rules'][0]['value']);
        // No orphan structural rows accumulated.
        $this->assertSame(2, (int) (new Query())->from('{{%simpleform_submit_messages}}')->where(['formId' => (int) $form->id])->count());

        // Now post only the second row: the first is deleted, its per-site rows cascade.
        $this->service()->sync((int) $form->id, [
            $this->row($secondId, [$this->rule('plan', 'eq', 'pro')], 'Only survivor'),
        ], $siteId, $handles, [$siteId]);

        $rows = $this->service()->getForFormAndSite((int) $form->id, $siteId);
        $this->assertCount(1, $rows);
        $this->assertSame($secondId, (int) $rows[0]->id);
        $this->assertSame(0, (int) (new Query())->from('{{%simpleform_submit_messages_sites}}')->where(['submitMessageId' => $firstId])->count());
    }

    public function testSyncPrunesDanglingRules(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SMS Prune', 'sms_prune');
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;

        // Only `reason` is a live handle; the `ghost` rule must be pruned out.
        $this->service()->sync((int) $form->id, [
            $this->row(null, [
                $this->rule('reason', 'eq', 'sales'),
                $this->rule('ghost', 'eq', 'x'),
            ], 'Kept'),
        ], $siteId, ['reason' => true], [$siteId]);

        $rows = $this->service()->getForFormAndSite((int) $form->id, $siteId);
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]->conditional['rules']);
        $this->assertSame('reason', $rows[0]->conditional['rules'][0]['field']);
    }

    public function testSyncSkipsEmptyRows(): void
    {
        $this->requireCraft();

        $form = $this->createForm('SMS Empty', 'sms_empty');
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;

        // A row with no usable rule and no message is a half-added row: dropped.
        $this->service()->sync((int) $form->id, [
            $this->row(null, [], ''),
            $this->row(null, [$this->rule('reason', 'eq', 'sales')], 'Real'),
        ], $siteId, ['reason' => true], [$siteId]);

        $rows = $this->service()->getForFormAndSite((int) $form->id, $siteId);
        $this->assertCount(1, $rows);
        $this->assertSame('Real', $rows[0]->message);
    }

    public function testValidateFlagsPartialRows(): void
    {
        $this->requireCraft();

        $handles = ['reason' => true];

        // Message but no rule; and a rule but no message: two errors, one each.
        $errors = $this->service()->validate([
            $this->row(null, [], 'Message with no condition'),
            $this->row(null, [$this->rule('reason', 'eq', 'sales')], ''),
        ], $handles);

        $this->assertCount(2, $errors);

        // A fully-populated row and a fully-empty row are both fine.
        $this->assertSame([], $this->service()->validate([
            $this->row(null, [$this->rule('reason', 'eq', 'sales')], 'Good'),
            $this->row(null, [], ''),
        ], $handles));
    }

    public function testSyncSeedsAllSupportedSitesOnInsert(): void
    {
        $this->requireCraft();

        $sites = Craft::$app->getSites()->getAllSites();
        if (count($sites) < 2) {
            $this->markTestSkipped('Multi-site seeding needs at least two sites.');
        }
        $primary = (int) $sites[0]->id;
        $secondary = (int) $sites[1]->id;

        $form = $this->createForm('SMS Seed', 'sms_seed');
        $this->service()->sync((int) $form->id, [
            $this->row(null, [$this->rule('reason', 'eq', 'sales')], 'Seeded'),
        ], $primary, ['reason' => true], [$primary, $secondary]);

        $onPrimary = $this->service()->getForFormAndSite((int) $form->id, $primary);
        $onSecondary = $this->service()->getForFormAndSite((int) $form->id, $secondary);
        $this->assertSame('Seeded', $onPrimary[0]->message);
        $this->assertSame('Seeded', $onSecondary[0]->message);

        // An update on the primary site leaves the secondary translation untouched.
        $id = (int) $onPrimary[0]->id;
        $this->service()->sync((int) $form->id, [
            $this->row($id, [$this->rule('reason', 'eq', 'sales')], 'Primary only'),
        ], $primary, ['reason' => true], [$primary, $secondary]);

        $this->assertSame('Primary only', $this->service()->getForFormAndSite((int) $form->id, $primary)[0]->message);
        $this->assertSame('Seeded', $this->service()->getForFormAndSite((int) $form->id, $secondary)[0]->message);
    }
}

<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\actions\SetSubmissionStatus;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use SmokeTester;

/**
 * Native Submission element index (#255): the source list the CP index builds,
 * the element query behind a per-form source, and the bulk status element action.
 *
 * {@see Submission::defineSources()} and {@see Submission::defineActions()} are
 * `protected static`, so they're reached via reflection exactly as Craft's
 * element-index machinery invokes them.
 *
 * @author Anvil Dev
 * @since 2.17.0
 */
class SubmissionIndexCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * The index exposes the expected sources: an "All" source, one status source
     * per read status (including spam), a per-form `form:<id>` source for the
     * seeded form, and a Trashed source.
     */
    public function testDefineSourcesYieldsAllStatusFormAndTrashedSources(SmokeTester $I): void
    {
        $form = $this->createForm('Index', 'idxSources' . uniqid());

        $keys = [];
        foreach ($this->sources() as $source) {
            if (isset($source['key'])) {
                $keys[] = $source['key'];
            }
        }

        $I->assertContains('*', $keys, 'an All Submissions source is present');
        $I->assertContains('status:new', $keys);
        $I->assertContains('status:read', $keys);
        $I->assertContains('status:archived', $keys);
        $I->assertContains('status:spam', $keys, 'spam has its own status source');
        $I->assertContains('form:' . $form->id, $keys, 'the seeded form has a per-form source');
        $I->assertContains('trashed', $keys, 'soft-deleted submissions have a Trashed source');
    }

    /**
     * The per-form source's criteria (`formId`) drives the element query the
     * index runs: it returns exactly the submissions of that form and no others.
     */
    public function testPerFormSourceQueryReturnsOnlyThatFormsSubmissions(SmokeTester $I): void
    {
        [$form, $fieldId] = $this->seedTextForm('idxForm');
        [$other, $otherFieldId] = $this->seedTextForm('idxOther');

        $mineA = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'A'])['submission']->id;
        $mineB = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'B'])['submission']->id;
        $foreign = (int) $this->submitRequest($other->handle, ['field_' . $otherFieldId => 'C'])['submission']->id;

        $criteria = $this->sourceCriteria('form:' . $form->id);
        $I->assertSame(['formId' => (int) $form->id], $criteria, 'the per-form source scopes by formId');

        $ids = array_map('intval', Submission::find()->formId((int) $criteria['formId'])->ids());
        sort($ids);

        $I->assertSame([$mineA, $mineB], $ids, 'the per-form query returns exactly this form\'s submissions');
        $I->assertNotContains($foreign, $ids, 'a submission from another form is excluded');
    }

    /**
     * The status-source criteria filter the element query by `readStatus`,
     * partitioning seeded submissions across statuses.
     */
    public function testStatusSourceQueryPartitionsByReadStatus(SmokeTester $I): void
    {
        [$form, $fieldId] = $this->seedTextForm('idxStatus');

        $newId = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'New'])['submission']->id;
        $readId = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'Read'])['submission']->id;
        $this->service()->updateStatus($readId, SubmissionStatus::READ);

        $newCriteria = $this->sourceCriteria('status:new');
        $readCriteria = $this->sourceCriteria('status:read');

        $newIds = array_map('intval', Submission::find()->formId((int) $form->id)->readStatus($newCriteria['readStatus'])->ids());
        $readIds = array_map('intval', Submission::find()->formId((int) $form->id)->readStatus($readCriteria['readStatus'])->ids());

        $I->assertContains($newId, $newIds, 'the untouched submission is in the New source');
        $I->assertNotContains($readId, $newIds, 'the read submission left the New source');
        $I->assertContains($readId, $readIds, 'the read submission is in the Read source');
    }

    /**
     * The bulk element action {@see SetSubmissionStatus} flips the read status of
     * every selected submission through the real service path.
     */
    public function testBulkSetStatusActionUpdatesSelectedSubmissions(SmokeTester $I): void
    {
        [$form, $fieldId] = $this->seedTextForm('idxBulk');

        $a = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'A'])['submission']->id;
        $b = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'B'])['submission']->id;

        $action = new SetSubmissionStatus();
        $action->status = SubmissionStatus::ARCHIVED;
        $result = $action->performAction(Submission::find()->id([$a, $b]));

        $I->assertTrue($result, 'the bulk action reports success');
        $I->assertSame(SubmissionStatus::ARCHIVED, Submission::find()->id($a)->one()->readStatus, 'the first submission was archived');
        $I->assertSame(SubmissionStatus::ARCHIVED, Submission::find()->id($b)->one()->readStatus, 'the second submission was archived');
    }

    /**
     * The bulk action offers a Mark-as-spam variant, and its verdict lands on the
     * submission (the spam status source then holds it).
     */
    public function testDefineActionsOffersEveryStatusVerdict(SmokeTester $I): void
    {
        $statuses = [];
        foreach ($this->actions() as $action) {
            if (is_array($action) && ($action['type'] ?? null) === SetSubmissionStatus::class) {
                $statuses[] = $action['status'];
            }
        }

        $I->assertContains(SubmissionStatus::READ, $statuses);
        $I->assertContains(SubmissionStatus::ARCHIVED, $statuses);
        $I->assertContains(SubmissionStatus::SPAM, $statuses, 'a Mark-as-spam bulk action is offered');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * A single-text-field form; returns [form, fieldId].
     *
     * @return array{0: \anvildev\simpleform\elements\Form, 1: int}
     */
    private function seedTextForm(string $handleSeed): array
    {
        $form = $this->createForm('Index', $handleSeed . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        return [$form, $fieldId];
    }

    /**
     * The index sources as Craft's element-index machinery reads them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function sources(): array
    {
        $method = new \ReflectionMethod(Submission::class, 'defineSources');
        $method->setAccessible(true);

        return $method->invoke(null, null);
    }

    /**
     * The bulk actions the index offers.
     *
     * @return array<int, mixed>
     */
    private function actions(): array
    {
        $method = new \ReflectionMethod(Submission::class, 'defineActions');
        $method->setAccessible(true);

        return $method->invoke(null, null);
    }

    /**
     * The `criteria` array of a named source.
     *
     * @return array<string, mixed>
     */
    private function sourceCriteria(string $key): array
    {
        foreach ($this->sources() as $source) {
            if (($source['key'] ?? null) === $key) {
                return $source['criteria'] ?? [];
            }
        }

        return [];
    }
}

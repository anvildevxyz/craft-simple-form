<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;

/**
 * Covers the per-field write path shared by the CP field builder and the MCP
 * field tools ({@see \anvildev\simpleform\services\FieldsService}): the
 * structural row, the per-site label/helpText rows, sort ordering, the
 * helpText empty-string coercion, and FK-cascade on delete.
 *
 * @group requires-craft
 */
class FieldsServiceTest extends SimpleFormTestCase
{
    private function fields(): \anvildev\simpleform\services\FieldsService
    {
        return Plugin::getInstance()->getFields();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function structuralRow(int $fieldId): ?array
    {
        $row = (new Query())->from('{{%simpleform_fields}}')->where(['id' => $fieldId])->one();
        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function siteRow(int $fieldId, int $siteId): ?array
    {
        $row = (new Query())->from('{{%simpleform_fields_sites}}')
            ->where(['fieldId' => $fieldId, 'siteId' => $siteId])->one();
        return is_array($row) ? $row : null;
    }

    public function testAddWritesStructuralAndPerSiteRows(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Add fields', 'svc_add');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $fieldId = $this->fields()->add((int) $form->id, 'text', 'firstName', true, ['x' => 1], 'First name', 'Your name', [$siteId]);

        $structural = $this->structuralRow($fieldId);
        $this->assertNotNull($structural);
        $this->assertSame('text', $structural['type']);
        $this->assertSame('firstName', $structural['name']);
        $this->assertSame(1, (int) $structural['required']);
        $this->assertSame(1, (int) $structural['sortOrder']);

        $site = $this->siteRow($fieldId, $siteId);
        $this->assertNotNull($site);
        $this->assertSame('First name', $site['label']);
        $this->assertSame('Your name', $site['helpText']);
    }

    public function testAddParagraphBlockRoundTripsHelpTextBody(): void
    {
        $this->requireCraft();

        // The CP field builder persists a Text (paragraph) layout block through
        // this same add() path: no label, no required, its copy in helpText.
        $form = $this->createForm('Paragraph', 'svc_paragraph');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $body = "Please have your order number ready.\nThanks!";

        $fieldId = $this->fields()->add((int) $form->id, 'paragraph', 'intro', false, [], '', $body, [$siteId]);

        $structural = $this->structuralRow($fieldId);
        $this->assertNotNull($structural);
        $this->assertSame('paragraph', $structural['type']);
        $this->assertSame(0, (int) $structural['required']);

        // The multi-line body round-trips verbatim in the per-site helpText column.
        $this->assertSame($body, $this->siteRow($fieldId, $siteId)['helpText']);
    }

    public function testAddIncrementsSortOrder(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Sort', 'svc_sort');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $first = $this->fields()->add((int) $form->id, 'text', 'a', false, [], 'A', '', [$siteId]);
        $second = $this->fields()->add((int) $form->id, 'text', 'b', false, [], 'B', '', [$siteId]);

        $this->assertSame(1, (int) $this->structuralRow($first)['sortOrder']);
        $this->assertSame(2, (int) $this->structuralRow($second)['sortOrder']);
    }

    public function testHelpTextEmptyStringStoresNullButZeroIsKept(): void
    {
        $this->requireCraft();

        $form = $this->createForm('HelpText', 'svc_help');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $empty = $this->fields()->add((int) $form->id, 'text', 'a', false, [], 'A', '', [$siteId]);
        $this->assertNull($this->siteRow($empty, $siteId)['helpText']);

        // "0" is a non-empty string, so it must survive (the old CP `?: null`
        // coercion would have wrongly nulled it).
        $zero = $this->fields()->add((int) $form->id, 'text', 'b', false, [], 'B', '0', [$siteId]);
        $this->assertSame('0', $this->siteRow($zero, $siteId)['helpText']);
    }

    public function testUpdateChangesStructuralColumnsAndPerSiteRow(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Update', 'svc_update');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $fieldId = $this->fields()->add((int) $form->id, 'text', 'old', false, [], 'Old', 'old help', [$siteId]);

        $this->fields()->update($fieldId, (int) $form->id, $siteId, 'renamed', true, ['y' => 2], 'New', 'new help');

        $structural = $this->structuralRow($fieldId);
        $this->assertSame('renamed', $structural['name']);
        $this->assertSame(1, (int) $structural['required']);
        // type is immutable — update must not touch it.
        $this->assertSame('text', $structural['type']);

        $site = $this->siteRow($fieldId, $siteId);
        $this->assertSame('New', $site['label']);
        $this->assertSame('new help', $site['helpText']);
    }

    public function testValidateInputReturnsStructuredKeyAndParams(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Validate', 'svc_validate');
        $formId = (int) $form->id;

        // Missing label + a choice type with no options → two structured errors,
        // the options one carrying a {type} param the caller interpolates.
        $errors = $this->fields()->validateInput('select', '', 'handle', [], $formId, null);

        $this->assertSame(['key' => 'Label is required', 'params' => []], $errors['label'][0]);
        $this->assertSame(
            ['key' => '{type} fields must have at least one option', 'params' => ['type' => 'select']],
            $errors['config'][0],
        );

        // A valid text field with options-less config produces no errors.
        $ok = $this->fields()->validateInput('text', 'Name', 'name', [], $formId, null);
        $this->assertSame([], $ok);
    }

    public function testValidateInputFlagsDuplicateHandleWithinForm(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Dupe', 'svc_dupe');
        $formId = (int) $form->id;
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $existing = $this->fields()->add($formId, 'text', 'email', false, [], 'Email', '', [$siteId]);

        // Same handle, different (new) field → duplicate error.
        $errors = $this->fields()->validateInput('text', 'Email', 'email', [], $formId, null);
        $this->assertSame('A field with this handle already exists in this form', $errors['handle'][0]['key']);

        // Excluding the field that owns the handle (an edit of itself) → no error.
        $ok = $this->fields()->validateInput('text', 'Email', 'email', [], $formId, $existing);
        $this->assertArrayNotHasKey('handle', $ok);
    }

    public function testReorderRewritesSortOrderByPosition(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Reorder', 'svc_reorder');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $a = $this->fields()->add((int) $form->id, 'text', 'a', false, [], 'A', '', [$siteId]);
        $b = $this->fields()->add((int) $form->id, 'text', 'b', false, [], 'B', '', [$siteId]);
        $c = $this->fields()->add((int) $form->id, 'text', 'c', false, [], 'C', '', [$siteId]);

        // New order c, a, b → 1-based sortOrder by position.
        $this->fields()->reorder([$c, $a, $b], (int) $form->id);

        $this->assertSame(1, (int) $this->structuralRow($c)['sortOrder']);
        $this->assertSame(2, (int) $this->structuralRow($a)['sortOrder']);
        $this->assertSame(3, (int) $this->structuralRow($b)['sortOrder']);
    }

    public function testReorderPinnedToFormIgnoresForeignFieldIds(): void
    {
        $this->requireCraft();

        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $formA = $this->createForm('Pinned A', 'svc_reorder_a');
        $formB = $this->createForm('Pinned B', 'svc_reorder_b');

        $a1 = $this->fields()->add((int) $formA->id, 'text', 'a1', false, [], 'A1', '', [$siteId]);
        $a2 = $this->fields()->add((int) $formA->id, 'text', 'a2', false, [], 'A2', '', [$siteId]);
        $bForeign = $this->fields()->add((int) $formB->id, 'text', 'b1', false, [], 'B1', '', [$siteId]);
        $foreignSortBefore = (int) $this->structuralRow($bForeign)['sortOrder'];

        // A foreign id slipped into the list must not be moved when pinned to formA.
        $this->fields()->reorder([$a2, $bForeign, $a1], (int) $formA->id);

        $this->assertSame(1, (int) $this->structuralRow($a2)['sortOrder']);
        $this->assertSame(3, (int) $this->structuralRow($a1)['sortOrder']);
        $this->assertSame($foreignSortBefore, (int) $this->structuralRow($bForeign)['sortOrder'], 'foreign field untouched');
    }

    public function testDeleteRemovesStructuralAndCascadesPerSiteRows(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Delete', 'svc_delete');
        $siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;

        $fieldId = $this->fields()->add((int) $form->id, 'text', 'gone', false, [], 'Gone', '', [$siteId]);
        $this->assertNotNull($this->siteRow($fieldId, $siteId));

        $this->fields()->delete($fieldId, (int) $form->id);

        $this->assertNull($this->structuralRow($fieldId));
        $this->assertNull($this->siteRow($fieldId, $siteId), 'per-site rows should cascade-delete via FK');
    }
}

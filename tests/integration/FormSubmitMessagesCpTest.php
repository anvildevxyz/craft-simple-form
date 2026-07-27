<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\controllers\FormsController;
use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use craft\web\Response;

/**
 * Conditional submit messages (#266) — the CP builder save path through
 * {@see \anvildev\simpleform\controllers\FormsController::actionSave()}: a posted
 * conditional-message set (rule + per-site text) round-trips through the form
 * save, and reordering the set persists the new sort order.
 *
 * @group requires-craft
 */
class FormSubmitMessagesCpTest extends SimpleFormTestCase
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

    private function setSolo(): void
    {
        $plugin = Plugin::getInstance();
        $this->originalEdition ??= $plugin->edition;
        $plugin->edition = Editions::SOLO;
    }

    /**
     * Invoke actionSave with the given body params and return the resolved form id.
     *
     * @param array<string, mixed> $params
     */
    private function save(int $formId, array $params): void
    {
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $request = Craft::$app->getRequest();
        $request->setBodyParams(array_merge([
            'formId' => $formId,
            'siteId' => $siteId,
            'name' => 'CP Messages',
            'handle' => 'cp_messages_form',
            'title' => 'CP Messages',
            'postSubmitAction' => 'message',
            'submitMessage' => 'Default message',
        ], $params));
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new FormsController('forms', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $controller->actionSave();
    }

    /**
     * The field builder payload for a single `reason` select field.
     */
    private function reasonFieldsData(): string
    {
        return (string) json_encode([[
            'id' => null,
            'type' => 'select',
            'handle' => 'reason',
            'label' => 'Reason',
            'required' => false,
            'config' => ['options' => [
                ['value' => 'sales', 'label' => 'Sales'],
                ['value' => 'support', 'label' => 'Support'],
                ['value' => 'other', 'label' => 'Other'],
            ]],
        ]]);
    }

    public function testAddConditionalMessageRoundTrips(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CP RT', 'cp_rt_form');
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;

        $this->save((int) $form->id, [
            'handle' => 'cp_rt_form',
            'name' => 'CP RT',
            'fieldsData' => $this->reasonFieldsData(),
            'submitMessagesData' => (string) json_encode([[
                'id' => null,
                'conditional' => ['match' => 'all', 'rules' => [
                    ['field' => 'reason', 'operator' => 'eq', 'value' => 'sales'],
                ]],
                'message' => 'A specialist will call you.',
            ]]),
        ]);

        $rows = Plugin::getInstance()->getSubmitMessages()->getForFormAndSite((int) $form->id, $siteId);
        $this->assertCount(1, $rows, 'the conditional message should persist');
        $this->assertSame('A specialist will call you.', $rows[0]->message);
        $this->assertTrue($rows[0]->conditional['enabled']);
        $this->assertSame('all', $rows[0]->conditional['match']);
        $this->assertSame('reason', $rows[0]->conditional['rules'][0]['field']);
        $this->assertSame('eq', $rows[0]->conditional['rules'][0]['operator']);
        $this->assertSame('sales', $rows[0]->conditional['rules'][0]['value']);
    }

    public function testReorderPersistsSortOrder(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CP Reorder', 'cp_reorder_form');
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $service = Plugin::getInstance()->getSubmitMessages();

        $this->save((int) $form->id, [
            'handle' => 'cp_reorder_form',
            'name' => 'CP Reorder',
            'fieldsData' => $this->reasonFieldsData(),
            'submitMessagesData' => (string) json_encode([
                [
                    'id' => null,
                    'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'sales']]],
                    'message' => 'First',
                ],
                [
                    'id' => null,
                    'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'support']]],
                    'message' => 'Second',
                ],
            ]),
        ]);

        $rows = $service->getForFormAndSite((int) $form->id, $siteId);
        $firstId = (int) $rows[0]->id;
        $secondId = (int) $rows[1]->id;
        $this->assertSame('First', $rows[0]->message);

        // Re-save with the two rows swapped, carrying their ids.
        $this->save((int) $form->id, [
            'handle' => 'cp_reorder_form',
            'name' => 'CP Reorder',
            'fieldsData' => $this->reasonFieldsData(),
            'submitMessagesData' => (string) json_encode([
                [
                    'id' => $secondId,
                    'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'support']]],
                    'message' => 'Second',
                ],
                [
                    'id' => $firstId,
                    'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'sales']]],
                    'message' => 'First',
                ],
            ]),
        ]);

        $rows = $service->getForFormAndSite((int) $form->id, $siteId);
        $this->assertSame([$secondId, $firstId], array_map(static fn($r): int => (int) $r->id, $rows));
        $this->assertSame(1, $rows[0]->sortOrder);
        $this->assertSame(2, $rows[1]->sortOrder);
        $this->assertSame('Second', $rows[0]->message);
    }

    /**
     * Smoke: configure two conditional messages with different rules through the
     * CP save path, then submit values matching each — the right message resolves
     * every time, and a submission matching neither falls back to the default.
     */
    public function testTwoMessagesResolveEndToEnd(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CP E2E', 'cp_e2e_form');

        $this->save((int) $form->id, [
            'handle' => 'cp_e2e_form',
            'name' => 'CP E2E',
            'submitMessage' => 'Thanks for getting in touch.',
            'fieldsData' => $this->reasonFieldsData(),
            'submitMessagesData' => (string) json_encode([
                [
                    'id' => null,
                    'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'sales']]],
                    'message' => 'A specialist will call you within 24h.',
                ],
                [
                    'id' => null,
                    'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'support']]],
                    'message' => 'Check your inbox for a ticket number.',
                ],
            ]),
        ]);

        // Reload the form so its saved field set is queryable for the submission.
        $saved = Form::find()->id((int) $form->id)->status(null)->one();
        $reasonId = (int) (new Query())
            ->select(['id'])->from('{{%simpleform_fields}}')
            ->where(['formId' => (int) $form->id, 'name' => 'reason'])->scalar();
        $this->assertGreaterThan(0, $reasonId);

        $this->assertSame('A specialist will call you within 24h.', $this->resolveMessage($saved, [$reasonId => 'sales']));
        $this->assertSame('Check your inbox for a ticket number.', $this->resolveMessage($saved, [$reasonId => 'support']));
        // Neither condition matches → the form's default message shows.
        $this->assertSame('Thanks for getting in touch.', $this->resolveMessage($saved, [$reasonId => 'other']));
    }

    /**
     * A field builder payload for a live `reason` select plus a `priority` select,
     * so a message rule can reference both.
     */
    private function reasonAndPriorityFieldsData(): string
    {
        return (string) json_encode([
            [
                'id' => null,
                'type' => 'select',
                'handle' => 'reason',
                'label' => 'Reason',
                'required' => false,
                'config' => ['options' => [
                    ['value' => 'sales', 'label' => 'Sales'],
                    ['value' => 'support', 'label' => 'Support'],
                ]],
            ],
            [
                'id' => null,
                'type' => 'select',
                'handle' => 'priority',
                'label' => 'Priority',
                'required' => false,
                'config' => ['options' => [
                    ['value' => 'high', 'label' => 'High'],
                    ['value' => 'low', 'label' => 'Low'],
                ]],
            ],
        ]);
    }

    /**
     * Save-time guard rail (#267): deleting/renaming a field that a conditional
     * message rule references surfaces a non-blocking warning on the next form
     * save (the row still saves — the dangling rule is pruned), while a form whose
     * rules all reference live fields saves warning-free.
     */
    public function testDanglingReferenceWarnsOnNextSave(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CP Dangling', 'cp_dangling_form');
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $service = Plugin::getInstance()->getSubmitMessages();

        // First save: an AND rule referencing both live fields — no warning, and
        // the row saves cleanly.
        $this->save((int) $form->id, [
            'handle' => 'cp_dangling_form',
            'name' => 'CP Dangling',
            'fieldsData' => $this->reasonAndPriorityFieldsData(),
            'submitMessagesData' => (string) json_encode([[
                'id' => null,
                'conditional' => ['match' => 'all', 'rules' => [
                    ['field' => 'reason', 'operator' => 'eq', 'value' => 'sales'],
                    ['field' => 'priority', 'operator' => 'eq', 'value' => 'high'],
                ]],
                'message' => 'A specialist will call you.',
            ]]),
        ]);
        $existingId = (int) $service->getForFormAndSite((int) $form->id, $siteId)[0]->id;
        $this->assertStringNotContainsString('no longer exists', (string) Craft::$app->getSession()->getNotice());

        // Second save: `priority` is dropped, but the stored message row still
        // references it. The live `reason` rule keeps the row valid (so the save
        // succeeds), the dangling `priority` rule is pruned, and the guard rail
        // warns rather than blocking the save.
        $this->save((int) $form->id, [
            'handle' => 'cp_dangling_form',
            'name' => 'CP Dangling',
            'fieldsData' => $this->reasonFieldsData(),
            'submitMessagesData' => (string) json_encode([[
                'id' => $existingId,
                'conditional' => ['match' => 'all', 'rules' => [
                    ['field' => 'reason', 'operator' => 'eq', 'value' => 'sales'],
                    ['field' => 'priority', 'operator' => 'eq', 'value' => 'high'],
                ]],
                'message' => 'A specialist will call you.',
            ]]),
        ]);

        $notice = (string) Craft::$app->getSession()->getNotice();
        $this->assertStringContainsString('priority', $notice);
        $this->assertStringContainsString('no longer exists', $notice);

        // The row survived (non-blocking): only the live `reason` rule remains.
        $rows = $service->getForFormAndSite((int) $form->id, $siteId);
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]->conditional['rules']);
        $this->assertSame('reason', $rows[0]->conditional['rules'][0]['field']);
    }

    public function testSoloCannotAddButKeepsEditingExisting(): void
    {
        $this->requireCraft();

        $form = $this->createForm('CP Gate', 'cp_gate_form');
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $service = Plugin::getInstance()->getSubmitMessages();

        // A first row created while effectively Pro.
        $this->save((int) $form->id, [
            'handle' => 'cp_gate_form',
            'name' => 'CP Gate',
            'fieldsData' => $this->reasonFieldsData(),
            'submitMessagesData' => (string) json_encode([[
                'id' => null,
                'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'sales']]],
                'message' => 'Existing',
            ]]),
        ]);
        $existingId = (int) $service->getForFormAndSite((int) $form->id, $siteId)[0]->id;

        $this->setSolo();

        // A new row is rejected on Solo — nothing new is persisted.
        $this->save((int) $form->id, [
            'handle' => 'cp_gate_form',
            'name' => 'CP Gate',
            'fieldsData' => $this->reasonFieldsData(),
            'submitMessagesData' => (string) json_encode([
                ['id' => $existingId, 'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'sales']]], 'message' => 'Existing'],
                ['id' => null, 'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'support']]], 'message' => 'New on solo'],
            ]),
        ]);
        $this->assertSame(1, (int) (new Query())->from('{{%simpleform_submit_messages}}')->where(['formId' => (int) $form->id])->count());

        // ...but the existing row stays editable so a downgraded site isn't stuck.
        $this->save((int) $form->id, [
            'handle' => 'cp_gate_form',
            'name' => 'CP Gate',
            'fieldsData' => $this->reasonFieldsData(),
            'submitMessagesData' => (string) json_encode([
                ['id' => $existingId, 'conditional' => ['match' => 'all', 'rules' => [['field' => 'reason', 'operator' => 'eq', 'value' => 'sales']]], 'message' => 'Edited on solo'],
            ]),
        ]);
        $this->assertSame('Edited on solo', $service->getForFormAndSite((int) $form->id, $siteId)[0]->message);
    }

    /**
     * Submit the form with the given field-id => value map and return the resolved
     * post-submit message.
     *
     * @param array<int, mixed> $fieldValues
     */
    private function resolveMessage(Form $form, array $fieldValues): string
    {
        $values = [];
        foreach ($fieldValues as $fieldId => $value) {
            $values['field_' . $fieldId] = $value;
        }
        $service = Plugin::getInstance()->getSubmissionService();
        $result = $service->submit($form, $values, ['skipCaptcha' => true]);

        return $service->resolvePostSubmit($form, $result['submission'], $result['data'])['message'];
    }
}

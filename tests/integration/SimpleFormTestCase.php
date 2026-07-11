<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use craft\helpers\StringHelper;
use craft\test\TestCase;

/**
 * Shared seeding helpers for the simple-form integration suite.
 *
 * Every test boots a real Craft inside the Craft module's per-test DB
 * transaction (configured in the root codeception.yml), so anything saved here
 * is rolled back after each test. Tests that touch Craft guard with
 * requireCraft() so the file still parses/skips cleanly if the framework is
 * unavailable.
 */
abstract class SimpleFormTestCase extends TestCase
{
    protected function _before(): void
    {
        parent::_before();

        // Submit rate limiting now defaults on (submitRateLimitPerMinute = 10) as
        // production hardening, but its cache counter isn't covered by the per-test
        // DB transaction and would otherwise accumulate across tests that share a
        // visitor IP, causing cross-test interference. Reset to a clean,
        // throttle-off baseline each test; the throttle tests opt in by setting
        // their own limit and start from the empty counter flushed here.
        if (class_exists(\Craft::class) && Craft::$app !== null) {
            Plugin::getInstance()->getSettings()->submitRateLimitPerMinute = 0;
            Craft::$app->getCache()->flush();
        }
    }

    protected function requireCraft(): void
    {
        if (!class_exists(\Craft::class) || Craft::$app === null) {
            $this->markTestSkipped('Craft application is not available.');
        }
    }

    /**
     * Create and persist a Form element for the given (or current) site.
     */
    protected function createForm(
        string $name,
        string $handle,
        ?string $title = null,
        ?int $siteId = null,
        ?string $emailTo = null,
        ?string $emailSubject = null,
    ): Form {
        $form = new Form();
        $form->name = $name;
        $form->handle = $handle;
        $form->title = $title ?? $name;
        $form->emailTo = $emailTo;
        $form->emailSubject = $emailSubject;
        if ($siteId !== null) {
            $form->siteId = $siteId;
        }

        $saved = Craft::$app->getElements()->saveElement($form);
        $this->assertTrue($saved, 'Form should save: ' . implode(', ', $form->getFirstErrors()));

        return $form;
    }

    /**
     * Insert a field for a form (structural row + per-site label/helpText rows),
     * mirroring how FieldsController persists a field. Returns the new field id.
     *
     * @param array<string,mixed> $config
     * @param array<int>|null     $siteIds sites to seed label/helpText for; defaults to all sites
     */
    protected function createField(
        int $formId,
        string $type,
        string $name,
        string $label,
        bool $required = false,
        array $config = [],
        ?array $siteIds = null,
        string $helpText = '',
        ?string $errorMessage = null,
    ): int {
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        $maxSort = (new \craft\db\Query())
            ->from('{{%simpleform_fields}}')
            ->where(['formId' => $formId])
            ->max('[[sortOrder]]');
        $sortOrder = ($maxSort !== false && $maxSort !== null ? (int) $maxSort : 0) + 1;

        $db->createCommand()->insert('{{%simpleform_fields}}', [
            'formId' => $formId,
            'type' => $type,
            'name' => $name,
            'required' => $required,
            'config' => $config,
            'sortOrder' => $sortOrder,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ])->execute();

        $fieldId = (int) $db->getLastInsertID();

        $siteIds ??= array_map(static fn($s) => $s->id, Craft::$app->getSites()->getAllSites());

        foreach ($siteIds as $siteId) {
            $db->createCommand()->insert('{{%simpleform_fields_sites}}', [
                'fieldId' => $fieldId,
                'siteId' => $siteId,
                'label' => $label,
                'helpText' => $helpText ?: null,
                'errorMessage' => $errorMessage,
                'dateCreated' => $now,
                'dateUpdated' => $now,
                'uid' => StringHelper::UUID(),
            ])->execute();
        }

        return $fieldId;
    }
}

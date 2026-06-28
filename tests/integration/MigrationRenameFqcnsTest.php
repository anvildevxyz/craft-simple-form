<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\fields\FormField;
use anvildev\simpleform\migrations\m260628_000001_rename_fqcns;
use anvildev\simpleform\widgets\SubmissionCountWidget;
use Craft;
use craft\db\Query;
use craft\helpers\StringHelper;

/**
 * Proves the namespace-rename migration actually un-breaks an install that ran an
 * earlier (fabianhaef-namespaced) release: every persisted component class name is
 * rewritten to the anvildev namespace, and a form whose element row carried the
 * old FQCN resolves again afterwards.
 *
 * @group requires-craft
 */
class MigrationRenameFqcnsTest extends SimpleFormTestCase
{
    private const OLD = 'fabianhaef\\simpleform\\';

    public function testRewritesPersistedClassNamesToAnvildev(): void
    {
        $this->requireCraft();
        $db = Craft::$app->getDb();
        $now = date('Y-m-d H:i:s');

        // A real Form whose element row we age back to the pre-rename FQCN.
        $form = $this->createForm('Legacy', 'legacyForm');
        $formId = (int)$form->id;
        $db->createCommand()
            ->update('{{%elements}}', ['type' => self::OLD . 'elements\\Form'], ['id' => $formId])
            ->execute();
        // Sanity-check the row really carries the pre-rename FQCN now.
        $this->assertSame(self::OLD . 'elements\\Form', $this->typeOf('{{%elements}}', $formId));

        // A raw Submission element row carrying the old FQCN.
        $submissionElementId = $this->insert('{{%elements}}', [
            'type' => self::OLD . 'elements\\Submission',
            'enabled' => true,
            'archived' => false,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);

        // A Craft custom field of the (old) FormField type.
        $fieldId = $this->insert('{{%fields}}', [
            'name' => 'Legacy Form Field',
            'handle' => 'legacyFormField',
            'context' => 'global',
            'type' => self::OLD . 'fields\\FormField',
            'searchable' => false,
            'translationMethod' => 'none',
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);

        // A dashboard widget of the (old) type.
        $userId = (new Query())->select(['id'])->from('{{%users}}')->scalar();
        $this->assertNotFalse($userId, 'a user is required to seed a widget row');
        $widgetId = $this->insert('{{%widgets}}', [
            'userId' => $userId,
            'type' => self::OLD . 'widgets\\SubmissionCountWidget',
            'sortOrder' => 1,
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]);

        // Run the rename.
        (new m260628_000001_rename_fqcns())->safeUp();

        // Both element rows are rewritten to the anvildev namespace, so Craft can
        // resolve the type again (the data-loss symptom is gone).
        $this->assertSame(Form::class, $this->typeOf('{{%elements}}', $formId));
        $this->assertSame(Submission::class, $this->typeOf('{{%elements}}', $submissionElementId));

        // The custom field and the dashboard widget are rewritten too.
        $this->assertSame(FormField::class, $this->typeOf('{{%fields}}', $fieldId));
        $this->assertSame(SubmissionCountWidget::class, $this->typeOf('{{%widgets}}', $widgetId));
    }

    /**
     * @param array<string, mixed> $columns
     */
    private function insert(string $table, array $columns): int
    {
        Craft::$app->getDb()->createCommand()->insert($table, $columns)->execute();
        return (int)Craft::$app->getDb()->getLastInsertID();
    }

    private function typeOf(string $table, int $id): string
    {
        return (string)(new Query())->select(['type'])->from($table)->where(['id' => $id])->scalar();
    }
}

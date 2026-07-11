<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\migrations\m260711_000002_add_dedupe_hashes;
use anvildev\simpleform\Plugin;
use craft\db\Query;

/**
 * The denormalized dedupe/guest-email hash columns (#341) that back the indexed
 * duplicate-detection and guest-email-limit lookups. Behavior parity for the
 * lookups themselves is covered end-to-end by {@see DuplicatePreventionTest} and
 * {@see UserLimitsTest}; this asserts the columns are populated on save with the
 * exact hashes those lookups query for.
 *
 * @group requires-craft
 */
class DedupeHashColumnsTest extends SimpleFormTestCase
{
    /** @return array{dedupeHash: ?string, guestEmailHash: ?string} */
    private function hashRow(int $submissionId): array
    {
        /** @var array{dedupeHash: ?string, guestEmailHash: ?string} $row */
        $row = (new Query())
            ->select(['dedupeHash', 'guestEmailHash'])
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $submissionId])
            ->one();

        return $row;
    }

    public function testEmailSubmissionPopulatesBothHashes(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Hash Email', 'hash_email');
        $form->duplicateKey = Form::DUPLICATE_KEY_EMAIL;
        $this->assertTrue(\Craft::$app->getElements()->saveElement($form));
        $emailField = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $service = Plugin::getInstance()->getSubmissionService();

        $result = $service->submit($form, ['field_' . $emailField => 'Bob@Example.com'], ['skipCaptcha' => true]);
        $row = $this->hashRow((int) $result['submission']->id);

        // Email dedupe key: SHA-256 of the 'email:'-prefixed lowercased fingerprint.
        $this->assertSame(hash('sha256', 'email:bob@example.com'), $row['dedupeHash']);
        // Guest-email hash: SHA-256 of the normalized (lowercased, trimmed) email.
        $this->assertSame(hash('sha256', 'bob@example.com'), $row['guestEmailHash']);
    }

    public function testContentKeyPopulatesDedupeHashWithoutEmail(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Hash Content', 'hash_content');
        $form->duplicateKey = Form::DUPLICATE_KEY_CONTENT;
        $this->assertTrue(\Craft::$app->getElements()->saveElement($form));
        $textField = $this->createField((int) $form->id, 'text', 'message', 'Message');
        $service = Plugin::getInstance()->getSubmissionService();

        $result = $service->submit($form, ['field_' . $textField => 'hello'], ['skipCaptcha' => true]);
        $row = $this->hashRow((int) $result['submission']->id);

        // Content key derives a dedupe hash but there is no email field, so the
        // guest-email hash stays null.
        $this->assertNotNull($row['dedupeHash']);
        $this->assertNull($row['guestEmailHash']);
    }

    public function testMigrationBackfillsNullHashes(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Hash Backfill', 'hash_backfill');
        $form->duplicateKey = Form::DUPLICATE_KEY_EMAIL;
        $this->assertTrue(\Craft::$app->getElements()->saveElement($form));
        $emailField = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $service = Plugin::getInstance()->getSubmissionService();

        $result = $service->submit($form, ['field_' . $emailField => 'legacy@example.com'], ['skipCaptcha' => true]);
        $id = (int) $result['submission']->id;

        // Simulate a pre-migration row: null out the hashes the migration backfills.
        \Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_submissions}}', ['dedupeHash' => null, 'guestEmailHash' => null], ['id' => $id])
            ->execute();
        $this->assertNull($this->hashRow($id)['dedupeHash']);

        // Run the migration's backfill (columns/indexes already exist → guarded no-ops).
        $migration = new m260711_000002_add_dedupe_hashes();
        $migration->db = \Craft::$app->getDb();
        $migration->compact = true;
        $this->assertTrue($migration->safeUp());

        $row = $this->hashRow($id);
        $this->assertSame(hash('sha256', 'email:legacy@example.com'), $row['dedupeHash'], 'backfill restores the dedupe hash');
        $this->assertSame(hash('sha256', 'legacy@example.com'), $row['guestEmailHash'], 'backfill restores the guest-email hash');
    }

    public function testEditRecomputesHash(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Hash Edit', 'hash_edit');
        $form->duplicateKey = Form::DUPLICATE_KEY_EMAIL;
        $this->assertTrue(\Craft::$app->getElements()->saveElement($form));
        $emailField = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $service = Plugin::getInstance()->getSubmissionService();

        $result = $service->submit($form, ['field_' . $emailField => 'first@example.com'], ['skipCaptcha' => true]);
        $submission = $result['submission'];
        $before = $this->hashRow((int) $submission->id);
        $this->assertSame(hash('sha256', 'first@example.com'), $before['guestEmailHash']);

        // Change the email and re-save the element directly; afterSave must re-key.
        $submission->data = ['field_' . $emailField => ['label' => 'Email', 'type' => 'email', 'value' => 'second@example.com']];
        $this->assertTrue(\Craft::$app->getElements()->saveElement($submission));

        $after = $this->hashRow((int) $submission->id);
        $this->assertSame(hash('sha256', 'second@example.com'), $after['guestEmailHash']);
        $this->assertSame(hash('sha256', 'email:second@example.com'), $after['dedupeHash']);
    }
}

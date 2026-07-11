<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\migrations\m260711_000002_add_dedupe_hashes;
use anvildev\simpleform\migrations\m260711_000004_rekey_privacy_hashes;
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
    /**
     * The keyed (HMAC-SHA256) hash the service now stores for the privacy/dedupe
     * columns — an unsalted SHA-256 of a low-entropy IP/email was reversible by
     * precomputation (CWE-759/916), so the value is keyed with the site security
     * key. Mirrors {@see \anvildev\simpleform\services\SubmissionService::keyedHash()}.
     */
    private function keyed(string $value): string
    {
        $key = (string) \Craft::$app->getConfig()->getGeneral()->securityKey;

        return hash_hmac('sha256', $value, $key);
    }

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

        // Email dedupe key: keyed hash of the 'email:'-prefixed lowercased fingerprint.
        $this->assertSame($this->keyed('email:bob@example.com'), $row['dedupeHash']);
        // Guest-email hash: keyed hash of the normalized (lowercased, trimmed) email.
        $this->assertSame($this->keyed('bob@example.com'), $row['guestEmailHash']);
        // Regression: the stored value must be the keyed HMAC, not a reversible
        // plain SHA-256 of the raw email (CWE-759/916).
        $this->assertNotSame(hash('sha256', 'bob@example.com'), $row['guestEmailHash']);
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
        $this->assertSame($this->keyed('email:legacy@example.com'), $row['dedupeHash'], 'backfill restores the dedupe hash');
        $this->assertSame($this->keyed('legacy@example.com'), $row['guestEmailHash'], 'backfill restores the guest-email hash');
    }

    public function testRekeyMigrationPurgesIpHashAndRekeysEmailHashes(): void
    {
        $this->requireCraft();
        $form = $this->createForm('Hash Rekey', 'hash_rekey');
        $form->duplicateKey = Form::DUPLICATE_KEY_EMAIL;
        $this->assertTrue(\Craft::$app->getElements()->saveElement($form));
        $emailField = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $service = Plugin::getInstance()->getSubmissionService();

        $result = $service->submit($form, ['field_' . $emailField => 'old@example.com'], ['skipCaptcha' => true]);
        $id = (int) $result['submission']->id;

        // Simulate a row written by the old code: reversible plain-SHA-256 hashes
        // and a populated (reversible) ipHash.
        \Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_submissions}}', [
                'ipHash' => hash('sha256', '203.0.113.7'),
                'dedupeHash' => hash('sha256', 'email:old@example.com'),
                'guestEmailHash' => hash('sha256', 'old@example.com'),
            ], ['id' => $id])
            ->execute();

        $migration = new m260711_000004_rekey_privacy_hashes();
        $migration->db = \Craft::$app->getDb();
        $migration->compact = true;
        $this->assertTrue($migration->safeUp());

        /** @var array{ipHash: ?string, dedupeHash: ?string, guestEmailHash: ?string} $row */
        $row = (new Query())
            ->select(['ipHash', 'dedupeHash', 'guestEmailHash'])
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $id])
            ->one();

        // The unrecoverable (raw IP gone) reversible ipHash is purged...
        $this->assertNull($row['ipHash']);
        // ...and the email-derived hashes are recomputed with the keyed HMAC.
        $this->assertSame($this->keyed('email:old@example.com'), $row['dedupeHash']);
        $this->assertSame($this->keyed('old@example.com'), $row['guestEmailHash']);
        $this->assertNotSame(hash('sha256', 'old@example.com'), $row['guestEmailHash']);
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
        $this->assertSame($this->keyed('first@example.com'), $before['guestEmailHash']);

        // Change the email and re-save the element directly; afterSave must re-key.
        $submission->data = ['field_' . $emailField => ['label' => 'Email', 'type' => 'email', 'value' => 'second@example.com']];
        $this->assertTrue(\Craft::$app->getElements()->saveElement($submission));

        $after = $this->hashRow((int) $submission->id);
        $this->assertSame($this->keyed('second@example.com'), $after['guestEmailHash']);
        $this->assertSame($this->keyed('email:second@example.com'), $after['dedupeHash']);
    }
}

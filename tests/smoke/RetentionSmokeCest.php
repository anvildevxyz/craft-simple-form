<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\console\controllers\SubmissionsController;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\models\Settings;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use SmokeTester;
use yii\console\ExitCode;

/**
 * Data-retention smoke tests (#136): the purge deletes submissions older than the
 * retention window while sparing recent ones, and anonymize mode keeps the row
 * but scrubs the submitted data.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class RetentionSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    /**
     * Filler-submission count for the batch-boundary test — comfortably past
     * RetentionService::BATCH (500) so the streamed scan crosses at least one
     * chunk boundary.
     */
    private const BATCH_BOUNDARY_FILLER_COUNT = 520;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testPurgeDeletesAgedSubmissionsOnly(SmokeTester $I): void
    {
        $form = $this->createForm('Retain', 'retainPurge' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $aged = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'Old'])['submission']->id;
        $recent = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'New'])['submission']->id;
        $this->backdate($aged, 90);

        $deleted = Plugin::getInstance()->getRetention()->purgeSubmissions(30, false);

        $I->assertGreaterThanOrEqual(1, $deleted);
        $I->assertNull(Submission::find()->id($aged)->trashed(null)->one(), 'the aged submission is purged');
        $I->assertNotNull(Submission::find()->id($recent)->one(), 'the recent submission survives');
    }

    public function testAnonymizeKeepsRowButScrubsData(SmokeTester $I): void
    {
        $form = $this->createForm('Retain', 'retainAnon' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $aged = (int) $this->submitRequest($form->handle, ['field_' . $fieldId => 'Old'])['submission']->id;
        $this->backdate($aged, 90);

        Plugin::getInstance()->getRetention()->purgeSubmissions(30, true);

        $row = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $aged])->one();
        $I->assertNotNull($row, 'the anonymized row remains');
        $I->assertNull($row['data'], 'the submitted data is scrubbed');
    }

    public function testRetentionDisabledWhenZeroDays(SmokeTester $I): void
    {
        $I->assertSame(0, Plugin::getInstance()->getRetention()->purgeSubmissions(0, false));
    }

    public function testIpCapturePolicyControlsStoredSourceIp(SmokeTester $I): void
    {
        $settings = Plugin::getInstance()->getSettings();
        $previous = $settings->ipCapturePolicy;
        $this->setRequestIp('203.0.113.42');

        try {
            $settings->ipCapturePolicy = Settings::IP_CAPTURE_FULL;
            $I->assertSame('203.0.113.42', $this->submitAndReadSourceIp('ipFull'), 'full mode stores the verbatim IP');

            $settings->ipCapturePolicy = Settings::IP_CAPTURE_ANONYMIZED;
            $I->assertSame('203.0.113.0', $this->submitAndReadSourceIp('ipAnon'), 'anonymized mode masks the last octet at capture time');

            $settings->ipCapturePolicy = Settings::IP_CAPTURE_OFF;
            $I->assertNull($this->submitAndReadSourceIp('ipOff'), 'off mode stores no IP');
        } finally {
            $settings->ipCapturePolicy = $previous;
            $this->setRequestIp(null);
        }
    }

    public function testExportByEmailReturnsOnlyMatchingSubmissions(SmokeTester $I): void
    {
        $email = 'gdpr-' . uniqid() . '@example.test';
        [$form, $emailFieldId, $nameFieldId] = $this->seedEmailForm();

        $alice = 'Alice-' . uniqid();
        $bob = 'Bob-' . uniqid();
        $carol = 'Carol-' . uniqid();
        $matchA = $this->submit($form, $emailFieldId, $nameFieldId, $email, $alice);
        $matchB = $this->submit($form, $emailFieldId, $nameFieldId, $email, $bob);
        $other = $this->submit($form, $emailFieldId, $nameFieldId, 'someone-else-' . uniqid() . '@example.test', $carol);

        $ids = Plugin::getInstance()->getRetention()->findSubmissionIdsByEmail($email);
        sort($ids);
        $I->assertSame([$matchA, $matchB], $ids, 'the shared query matches exactly the two same-email submissions');

        $path = (string) tempnam(sys_get_temp_dir(), 'sf-export');
        $controller = new SubmissionsController('submissions', Plugin::getInstance());
        $controller->email = $email;
        $controller->out = $path;

        $I->assertSame(ExitCode::OK, $controller->actionExportByEmail());
        $csv = (string) file_get_contents($path);
        @unlink($path);

        $I->assertStringContainsString($alice, $csv, 'the export includes the first matching submission');
        $I->assertStringContainsString($bob, $csv, 'the export includes the second matching submission');
        $I->assertStringNotContainsString($carol, $csv, 'the export excludes the non-matching submission');
        $firstDataRow = explode("\n", $csv)[1] ?? '';
        // Anchored to the row's id column (not a bare substring match) so this
        // never collides with the non-matching id's digits incidentally
        // appearing inside another column, e.g. today's date.
        $I->assertFalse(str_starts_with($firstDataRow, $other . ','), 'the non-matching id is not the first data row');
    }

    /**
     * findSubmissionIdsByEmail() streams the submissions table in bounded
     * batches (#325) rather than loading every row via `all()`. Seed enough
     * filler rows to cross at least one batch boundary and confirm the result
     * is still exactly the matching ids — streaming must not drop or duplicate
     * rows at the chunk edges.
     */
    public function testFindSubmissionIdsByEmailStreamsAcrossBatchBoundary(SmokeTester $I): void
    {
        $email = 'gdpr-batch-' . uniqid() . '@example.test';
        [$form, $emailFieldId, $nameFieldId] = $this->seedEmailForm();

        $match = $this->submit($form, $emailFieldId, $nameFieldId, $email, 'BatchMatch');
        $this->seedFillerSubmissions($form, self::BATCH_BOUNDARY_FILLER_COUNT);
        $other = $this->submit($form, $emailFieldId, $nameFieldId, 'someone-else-' . uniqid() . '@example.test', 'Other');

        $ids = Plugin::getInstance()->getRetention()->findSubmissionIdsByEmail($email);

        $I->assertSame([$match], $ids, 'only the matching submission is returned despite the filler rows');
        $I->assertNotContains($other, $ids, 'the non-matching submission is excluded');
    }

    public function testEraseByEmailDeletesOnlyMatchingAndDryRunIsNoOp(SmokeTester $I): void
    {
        $email = 'gdpr-' . uniqid() . '@example.test';
        [$form, $emailFieldId, $nameFieldId] = $this->seedEmailForm();

        $matchA = $this->submit($form, $emailFieldId, $nameFieldId, $email, 'A');
        $matchB = $this->submit($form, $emailFieldId, $nameFieldId, $email, 'B');
        $other = $this->submit($form, $emailFieldId, $nameFieldId, 'keep-' . uniqid() . '@example.test', 'C');

        // Dry run: reports scope, mutates nothing.
        $dry = new SubmissionsController('submissions', Plugin::getInstance());
        $dry->email = $email;
        $dry->dryRun = true;
        $I->assertSame(ExitCode::OK, $dry->actionEraseByEmail());
        $I->assertNotNull(Submission::find()->id($matchA)->one(), 'dry run leaves the first match');
        $I->assertNotNull(Submission::find()->id($matchB)->one(), 'dry run leaves the second match');

        // Real run: hard-deletes only the matches.
        $run = new SubmissionsController('submissions', Plugin::getInstance());
        $run->email = $email;
        $I->assertSame(ExitCode::OK, $run->actionEraseByEmail());

        $I->assertNull(Submission::find()->id($matchA)->trashed(null)->one(), 'the first match is deleted');
        $I->assertNull(Submission::find()->id($matchB)->trashed(null)->one(), 'the second match is deleted');
        $I->assertNotNull(Submission::find()->id($other)->one(), 'the non-matching submission survives');
        $I->assertSame(
            [],
            Plugin::getInstance()->getRetention()->findSubmissionIdsByEmail($email),
            'no rows remain for the erased email',
        );
    }

    public function testEraseByEmailAnonymizeScrubsOnlyMatchingRows(SmokeTester $I): void
    {
        $email = 'gdpr-' . uniqid() . '@example.test';
        [$form, $emailFieldId, $nameFieldId] = $this->seedEmailForm();

        $match = $this->submit($form, $emailFieldId, $nameFieldId, $email, 'Scrub');
        $other = $this->submit($form, $emailFieldId, $nameFieldId, 'keep-' . uniqid() . '@example.test', 'Keep');

        $controller = new SubmissionsController('submissions', Plugin::getInstance());
        $controller->email = $email;
        $controller->anonymize = true;
        $I->assertSame(ExitCode::OK, $controller->actionEraseByEmail());

        $matchRow = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $match])->one();
        $otherRow = (new Query())->from('{{%simpleform_submissions}}')->where(['id' => $other])->one();

        $I->assertNotNull($matchRow, 'the anonymized row remains');
        $I->assertNull($matchRow['data'], 'the matching submission data is scrubbed');
        $I->assertNotNull($otherRow['data'], 'the non-matching submission data is untouched');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * A form with an Email field and a Text field, for the GDPR-by-email commands.
     *
     * @return array{0: Form, 1: int, 2: int}
     */
    private function seedEmailForm(): array
    {
        $form = $this->createForm('GDPR', 'gdpr' . uniqid());
        $emailFieldId = $this->createField((int) $form->id, 'email', 'email', 'Email');
        $nameFieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        return [$form, $emailFieldId, $nameFieldId];
    }

    private function submit(Form $form, int $emailFieldId, int $nameFieldId, string $email, string $name): int
    {
        return (int) $this->submitRequest($form->handle, [
            'field_' . $emailFieldId => $email,
            'field_' . $nameFieldId => $name,
        ])['submission']->id;
    }

    /**
     * Save $count bare, non-matching submissions directly via saveElement() —
     * skipping the request/notification pipeline — purely to build table volume
     * for the batch-boundary streaming test.
     */
    private function seedFillerSubmissions(Form $form, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $submission = new Submission();
            $submission->formId = (int) $form->id;
            $submission->siteId = $form->siteId;
            $submission->data = ['name' => ['label' => 'Name', 'type' => 'text', 'value' => 'Filler ' . $i]];

            Craft::$app->getElements()->saveElement($submission, false);
        }
    }

    private function backdate(int $submissionId, int $days): void
    {
        $old = (new \DateTime("-$days days"))->format('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_submissions}}', ['dateCreated' => $old], ['id' => $submissionId])
            ->execute();
    }

    /**
     * Submit a fresh single-field form and return the sourceIp stored on the
     * resulting submission.
     */
    private function submitAndReadSourceIp(string $handleSeed): ?string
    {
        $form = $this->createForm('IP', $handleSeed . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $submission = $this->submitRequest($form->handle, ['field_' . $fieldId => 'x'])['submission'];

        return $submission?->sourceIp;
    }

    /**
     * Force the request's resolved IP (memoized private field) so capture-time
     * masking can be asserted deterministically. Passing null resets it.
     */
    private function setRequestIp(?string $ip): void
    {
        $request = Craft::$app->getRequest();
        $property = new \ReflectionProperty($request, '_ipAddress');
        $property->setValue($request, $ip ?? false);
    }
}

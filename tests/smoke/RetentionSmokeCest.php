<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\models\Settings;
use anvildev\simpleform\Plugin;
use Craft;
use craft\db\Query;
use SmokeTester;

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

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

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

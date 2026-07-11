<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\elements\SubmissionStatus;
use anvildev\simpleform\models\Settings;
use anvildev\simpleform\Plugin;
use Craft;
use SmokeTester;

/**
 * Spam-retention window (#338): {@see \anvildev\simpleform\services\RetentionService::purgeSpam()}
 * prunes flagged-spam submissions older than `retainSpamDays`, while sparing
 * recent spam and every non-spam submission of the same age.
 *
 * @author Fabian Haefliger
 * @since 2.17.0
 */
class SpamRetentionCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    /**
     * The retention window ships enabled with a 30-day default.
     */
    public function testRetainSpamDaysDefaultsToThirty(SmokeTester $I): void
    {
        $I->assertSame(30, (new Settings())->retainSpamDays, 'spam is retained 30 days by default');
    }

    /**
     * purgeSpam deletes only aged spam: recent spam and a same-age non-spam
     * submission both survive.
     */
    public function testPurgeSpamDeletesAgedSpamOnly(SmokeTester $I): void
    {
        $form = $this->createForm('Spam', 'spamPurge' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $agedSpam = $this->submit($form->handle, $fieldId, 'AgedSpam');
        $recentSpam = $this->submit($form->handle, $fieldId, 'RecentSpam');
        $agedHam = $this->submit($form->handle, $fieldId, 'AgedHam');

        // Two spam rows (one aged past the window, one fresh) + a same-age
        // legitimate submission that must never be touched.
        $this->markSpam($agedSpam);
        $this->markSpam($recentSpam);
        $this->backdate($agedSpam, 45);
        $this->backdate($agedHam, 45);

        $deleted = Plugin::getInstance()->getRetention()->purgeSpam(30);

        $I->assertGreaterThanOrEqual(1, $deleted, 'at least the aged spam row is purged');
        $I->assertNull(Submission::find()->id($agedSpam)->trashed(null)->one(), 'the aged spam is deleted');
        $I->assertNotNull(Submission::find()->id($recentSpam)->one(), 'recent spam survives the window');
        $I->assertNotNull(Submission::find()->id($agedHam)->one(), 'a same-age legitimate submission is untouched');
    }

    /**
     * purgeSpam(0) is a no-op — 0 means "keep spam forever".
     */
    public function testPurgeSpamZeroDaysIsNoOp(SmokeTester $I): void
    {
        $form = $this->createForm('Spam', 'spamNoop' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $spam = $this->submit($form->handle, $fieldId, 'Spam');
        $this->markSpam($spam);
        $this->backdate($spam, 365);

        $I->assertSame(0, Plugin::getInstance()->getRetention()->purgeSpam(0), 'a zero window purges nothing');
        $I->assertNotNull(Submission::find()->id($spam)->one(), 'the aged spam survives when retention is disabled');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function submit(string $handle, int $fieldId, string $value): int
    {
        return (int) $this->submitRequest($handle, ['field_' . $fieldId => $value])['submission']->id;
    }

    private function markSpam(int $submissionId): void
    {
        Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_submissions}}', ['readStatus' => SubmissionStatus::SPAM], ['id' => $submissionId])
            ->execute();
    }

    private function backdate(int $submissionId, int $days): void
    {
        $old = (new \DateTime("-$days days"))->format('Y-m-d H:i:s');
        Craft::$app->getDb()->createCommand()
            ->update('{{%simpleform_submissions}}', ['dateCreated' => $old], ['id' => $submissionId])
            ->execute();
    }
}

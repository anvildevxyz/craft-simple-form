<?php

namespace fabianhaef\simpleform\services;

use Craft;
use craft\helpers\Db;
use fabianhaef\simpleform\elements\Submission;
use yii\base\Component;

/**
 * Front-end submission edit tokens (#144). A token authorizes an anonymous
 * submitter to re-open and edit a single submission via a secure link.
 *
 * Security model, mirroring {@see DraftService}:
 *  - The token is a high-entropy random string (256 bits). Only its SHA-256 hash
 *    is persisted (on the submission row); the plaintext token lives solely in
 *    the edit URL, so a database read alone cannot reissue a working link.
 *  - Verification is constant-time ({@see hash_equals()}) so a timing side
 *    channel can't be used to guess the hash.
 *  - A token is single-purpose: bound to exactly one submission id. A token for
 *    submission A never authorizes editing submission B.
 *  - Tokens carry an absolute expiry; verification ALSO re-checks the form's
 *    `editWindowMinutes` against the submission's creation time, so the window is
 *    authoritative even when a token's intrinsic expiry is longer.
 *  - The token is never logged in plaintext and never exposed via GraphQL/MCP.
 */
class SubmissionEditTokenService extends Component
{
    /** Default intrinsic token lifetime (days) when the form sets no edit window. */
    private const DEFAULT_LIFETIME_DAYS = 30;

    /**
     * Issue (or re-issue) an edit token for a submission and persist its hash +
     * expiry on the submission row. Returns the plaintext token for embedding in
     * an edit URL. Re-issuing rotates the token, invalidating any prior link.
     *
     * The token's intrinsic expiry is the form's edit window (when set) so the
     * link can never outlive the window; otherwise a generous default applies.
     */
    public function issue(Submission $submission): string
    {
        $token = bin2hex(random_bytes(32));

        $form = $submission->getForm();
        $windowMinutes = $form !== null ? (int) $form->editWindowMinutes : 0;
        $expires = $windowMinutes > 0
            ? (new \DateTime('@' . $this->submissionCreatedTimestamp($submission)))->modify('+' . $windowMinutes . ' minutes')
            : (new \DateTime())->modify('+' . self::DEFAULT_LIFETIME_DAYS . ' days');

        $submission->editTokenHash = $this->hash($token);
        $submission->editTokenExpires = Db::prepareDateForDb($expires);
        $this->persist($submission, $submission->editTokenHash, $submission->editTokenExpires);

        return $token;
    }

    /**
     * Whether $token is the active, unexpired edit token for $submission. Uses a
     * constant-time hash comparison and rejects when no token has been issued,
     * the hashes differ, or the token's intrinsic expiry has passed. The form's
     * edit window is enforced separately by {@see isWithinEditWindow()}.
     */
    public function verify(Submission $submission, ?string $token): bool
    {
        if ($token === null || $token === '' || $submission->editTokenHash === null) {
            return false;
        }

        if (!hash_equals($submission->editTokenHash, $this->hash($token))) {
            return false;
        }

        if ($submission->editTokenExpires !== null) {
            $expires = Db::prepareDateForDb($submission->editTokenExpires);
            return $expires === null || $expires >= Db::prepareDateForDb(new \DateTime());
        }

        return true;
    }

    /**
     * Whether the form's edit window is still open for this submission. A window
     * of 0 means unlimited (while editing is allowed). Always evaluated against
     * the submission's creation time so the window is authoritative server-side.
     */
    public function isWithinEditWindow(Submission $submission, int $windowMinutes): bool
    {
        if ($windowMinutes <= 0) {
            return true;
        }

        $deadline = (new \DateTime('@' . $this->submissionCreatedTimestamp($submission)))
            ->modify('+' . $windowMinutes . ' minutes');

        return new \DateTime() <= $deadline;
    }

    /**
     * Invalidate the submission's edit token (e.g. after a single-use edit).
     */
    public function invalidate(Submission $submission): void
    {
        $submission->editTokenHash = null;
        $submission->editTokenExpires = null;
        $this->persist($submission, null, null);
    }

    /** Write the token hash + expiry to the submission row. */
    private function persist(Submission $submission, ?string $hash, ?string $expires): void
    {
        Craft::$app->getDb()->createCommand()->update(
            '{{%simpleform_submissions}}',
            ['editTokenHash' => $hash, 'editTokenExpires' => $expires],
            ['id' => $submission->id],
        )->execute();
    }

    /**
     * UNIX timestamp of the submission's creation, defaulting to "now" when the
     * element has no dateCreated yet (a freshly built element pre-save).
     */
    private function submissionCreatedTimestamp(Submission $submission): int
    {
        return $submission->dateCreated?->getTimestamp() ?? time();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}

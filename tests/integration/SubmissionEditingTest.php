<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\controllers\SubmissionEditController;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\events\SubmissionEvent;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\web\twig\variables\SimpleFormVariable;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Response;
use yii\web\ForbiddenHttpException;

/**
 * Front-end submission editing (#144): edit-token issue/verify, the authorization
 * matrix, the shared save core, audit logging, and the after-save isNew flag.
 *
 * @group requires-craft
 */
class SubmissionEditingTest extends SimpleFormTestCase
{
    /** Create and persist a real user, returning its id (the userId FK needs a valid row). */
    private function createUser(string $email): int
    {
        $user = new \craft\elements\User();
        $user->username = $email;
        $user->email = $email;
        $this->assertTrue(Craft::$app->getElements()->saveElement($user), 'User should save');
        return (int) $user->id;
    }

    private function editableForm(string $handle, int $windowMinutes = 0): Form
    {
        $form = $this->createForm('Editable', $handle);
        $form->allowEditing = true;
        $form->editWindowMinutes = $windowMinutes;
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        return $form;
    }

    /** Create a saved submission for $form with one text field carrying $value. */
    private function submissionWith(Form $form, int $fieldId, string $value, ?int $userId = null): Submission
    {
        $result = Plugin::getInstance()->getSubmissionService()->submit($form, [$fieldId => $value], [
            'skipCaptcha' => true,
            'userId' => $userId,
        ]);
        $this->assertInstanceOf(Submission::class, $result['submission']);
        return $result['submission'];
    }

    public function testFormEditingFlagsPersist(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('persist_editing', 45);

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertTrue($reloaded->allowEditing);
        $this->assertSame(45, $reloaded->editWindowMinutes);
    }

    public function testTokenIssueStoresHashNotPlaintextAndVerifies(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('token_roundtrip');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $submission = $this->submissionWith($form, $fieldId, 'Ada');

        $tokens = Plugin::getInstance()->getSubmissionEditTokens();
        $token = $tokens->issue($submission);

        $this->assertNotSame('', $token);
        // Plaintext token is never stored — only its hash.
        $stored = (new Query())
            ->select(['editTokenHash'])
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $submission->id])
            ->scalar();
        $this->assertSame(hash('sha256', $token), $stored);
        $this->assertNotSame($token, $stored);

        // Valid token verifies; a tampered/empty/wrong token does not.
        $reloaded = Submission::find()->id($submission->id)->one();
        $this->assertTrue($tokens->verify($reloaded, $token));
        $this->assertFalse($tokens->verify($reloaded, $token . 'x'));
        $this->assertFalse($tokens->verify($reloaded, ''));
        $this->assertFalse($tokens->verify($reloaded, null));
    }

    public function testTokenForOneSubmissionDoesNotAuthorizeAnother(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('token_scope');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $a = $this->submissionWith($form, $fieldId, 'A');
        $b = $this->submissionWith($form, $fieldId, 'B');

        $tokens = Plugin::getInstance()->getSubmissionEditTokens();
        $tokenA = $tokens->issue($a);

        $reloadedB = Submission::find()->id($b->id)->one();
        $this->assertFalse($tokens->verify($reloadedB, $tokenA));
        $this->assertNull(Plugin::getInstance()->getSubmissionService()->authorizeEdit($reloadedB, $tokenA, null));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('token_expiry');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $submission = $this->submissionWith($form, $fieldId, 'Ada');

        $tokens = Plugin::getInstance()->getSubmissionEditTokens();
        $token = $tokens->issue($submission);

        // Force the token's intrinsic expiry into the past.
        Craft::$app->getDb()->createCommand()->update(
            '{{%simpleform_submissions}}',
            ['editTokenExpires' => Db::prepareDateForDb((new \DateTime())->modify('-1 hour'))],
            ['id' => $submission->id],
        )->execute();

        $reloaded = Submission::find()->id($submission->id)->one();
        $this->assertFalse($tokens->verify($reloaded, $token));
    }

    public function testEditWindowIsEnforcedEvenWithValidToken(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('window_enforced', 10);
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $submission = $this->submissionWith($form, $fieldId, 'Ada');

        $tokens = Plugin::getInstance()->getSubmissionEditTokens();
        $token = $tokens->issue($submission);

        // Inside the window: authorized.
        $reloaded = Submission::find()->id($submission->id)->one();
        $this->assertSame('token', Plugin::getInstance()->getSubmissionService()->authorizeEdit($reloaded, $token, null));

        // Push dateCreated back beyond the window — the window is authoritative.
        Craft::$app->getDb()->createCommand()->update(
            '{{%elements}}',
            ['dateCreated' => Db::prepareDateForDb((new \DateTime())->modify('-20 minutes'))],
            ['id' => $submission->id],
        )->execute();

        $stale = Submission::find()->id($submission->id)->one();
        $this->assertNull(Plugin::getInstance()->getSubmissionService()->authorizeEdit($stale, $token, null));
    }

    public function testEditingDisabledFormRefusesAllEdits(): void
    {
        $this->requireCraft();
        // allowEditing defaults to false.
        $form = $this->createForm('Locked', 'locked_form');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $submission = $this->submissionWith($form, $fieldId, 'Ada');

        $tokens = Plugin::getInstance()->getSubmissionEditTokens();
        $token = $tokens->issue($submission);

        $reloaded = Submission::find()->id($submission->id)->one();
        $this->assertNull(Plugin::getInstance()->getSubmissionService()->authorizeEdit($reloaded, $token, null));
    }

    public function testLoggedInOwnerCanEditWithoutTokenButOthersCannot(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('owner_edit');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $ownerId = $this->createUser('owner_edit@example.test');
        $otherId = $this->createUser('other_edit@example.test');
        $submission = $this->submissionWith($form, $fieldId, 'Ada', $ownerId);

        $reloaded = Submission::find()->id($submission->id)->one();
        $service = Plugin::getInstance()->getSubmissionService();

        // Owner, no token → authorized.
        $this->assertSame('user', $service->authorizeEdit($reloaded, null, $ownerId));
        // A different user, no token → refused.
        $this->assertNull($service->authorizeEdit($reloaded, null, $otherId));
        // Anonymous, no token → refused.
        $this->assertNull($service->authorizeEdit($reloaded, null, null));
    }

    public function testUpdatePreservesIdentityAndFiresAfterSaveWithIsNewFalse(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('update_identity');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $userId = $this->createUser('update_identity@example.test');
        $submission = $this->submissionWith($form, $fieldId, 'Ada', $userId);

        $originalId = $submission->id;
        $originalCreated = $submission->dateCreated?->getTimestamp();
        $originalSite = $submission->siteId;

        $captured = [];
        $handler = function(SubmissionEvent $e) use (&$captured): void {
            $captured[] = $e->isNew;
        };
        Plugin::getInstance()->on(Plugin::EVENT_AFTER_SUBMISSION_SAVE, $handler);

        $reloaded = Submission::find()->id($submission->id)->one();
        $result = Plugin::getInstance()->getSubmissionService()->update($reloaded, [$fieldId => 'Grace'], [
            'skipCaptcha' => true,
            'actor' => 'user',
        ]);
        Plugin::getInstance()->off(Plugin::EVENT_AFTER_SUBMISSION_SAVE, $handler);

        $this->assertNull($result['errors']);
        $this->assertSame($originalId, $result['submission']->id);
        $this->assertSame($originalCreated, $result['submission']->dateCreated?->getTimestamp());
        $this->assertSame($originalSite, $result['submission']->siteId);
        $this->assertSame($userId, $result['submission']->userId);
        $this->assertContains(false, $captured, 'after-save should fire with isNew=false on edit');

        // Stored content reflects the edit.
        $final = Submission::find()->id($originalId)->one();
        $this->assertSame('Grace', $final->data['field_' . $fieldId]['value']);
    }

    public function testEditWritesAuditEntry(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('audit_edit');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $userId = $this->createUser('audit_edit@example.test');
        $submission = $this->submissionWith($form, $fieldId, 'Ada', $userId);

        $reloaded = Submission::find()->id($submission->id)->one();
        Plugin::getInstance()->getSubmissionService()->update($reloaded, [$fieldId => 'Grace'], [
            'skipCaptcha' => true,
            'actor' => 'user',
        ]);

        $count = (new Query())
            ->from('{{%simpleform_audit_log}}')
            ->where(['action' => 'submission.edit', 'targetType' => 'submission', 'targetId' => $submission->id])
            ->count();
        $this->assertSame(1, (int) $count);
    }

    public function testEditFormRendersPreFilledWithTokenAndSubmissionId(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('render_edit');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $submission = $this->submissionWith($form, $fieldId, 'Grace Hopper');

        $token = Plugin::getInstance()->getSubmissionEditTokens()->issue($submission);
        $html = Plugin::getInstance()->getFormRender()->renderEditForm($submission, ['token' => $token]);

        $this->assertStringContainsString('Grace Hopper', $html);
        $this->assertStringContainsString('simple-form-edit', $html);
        $this->assertStringContainsString('name="submissionId" value="' . $submission->id . '"', $html);
        $this->assertStringContainsString('name="t" value="' . $token . '"', $html);
    }

    public function testEditFormRefusesWhenEditingDisabled(): void
    {
        $this->requireCraft();
        $form = $this->createForm('NoEdit', 'render_noedit'); // allowEditing false
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $submission = $this->submissionWith($form, $fieldId, 'X');

        $html = Plugin::getInstance()->getFormRender()->renderEditForm($submission);
        $this->assertStringContainsString('Editing is not enabled', $html);
    }

    public function testEditUrlReturnsTokenizedUrlOnlyWhenPathConfigured(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('edit_url');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $submission = $this->submissionWith($form, $fieldId, 'X');

        $variable = new SimpleFormVariable();

        // No path → null.
        $this->assertNull($variable->editUrl($submission));

        // Explicit path → a URL carrying the id + token.
        $url = $variable->editUrl($submission, 'forms/edit-submission');
        $this->assertIsString($url);
        $this->assertStringContainsString('id=' . $submission->id, $url);
        $this->assertStringContainsString('t=', $url);
    }

    public function testUpdateControllerReSavesWithValidTokenAndRejectsTampered(): void
    {
        $this->requireCraft();
        $form = $this->editableForm('ctrl_update');
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name', false);
        $submission = $this->submissionWith($form, $fieldId, 'Ada');
        $token = Plugin::getInstance()->getSubmissionEditTokens()->issue($submission);

        // Valid token → success + stored content updated.
        $data = $this->callUpdate((int) $submission->id, $token, ['field_' . $fieldId => 'Grace']);
        $this->assertTrue($data['success'] ?? false);
        $final = Submission::find()->id($submission->id)->one();
        $this->assertSame('Grace', $final->data['field_' . $fieldId]['value']);

        // Tampered token → 403, no change.
        $threw = false;
        try {
            $this->callUpdate((int) $submission->id, $token . 'tamper', ['field_' . $fieldId => 'Hacked']);
        } catch (ForbiddenHttpException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'A tampered token must be refused with 403');
        $unchanged = Submission::find()->id($submission->id)->one();
        $this->assertSame('Grace', $unchanged->data['field_' . $fieldId]['value']);
    }

    /**
     * Invoke the front-end update action and return its JSON data.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     * @throws ForbiddenHttpException
     */
    private function callUpdate(int $submissionId, string $token, array $fields): array
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(['submissionId' => $submissionId, 't' => $token] + $fields);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new SubmissionEditController('submission-edit', Plugin::getInstance());
        $controller->enableCsrfValidation = false;

        /** @var array<string, mixed> $data */
        $data = $controller->actionUpdate()->data;
        return $data;
    }
}

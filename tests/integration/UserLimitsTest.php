<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\User;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * Server-side enforcement of login-required + per-user submission limits +
 * user association (#135). Exercises the single SubmissionService::submit()
 * entry point shared by every transport, so a passing test here covers the
 * AJAX, no-JS, and GraphQL paths.
 *
 * @group requires-craft
 */
class UserLimitsTest extends SimpleFormTestCase
{
    public function testRequireLoginRejectsGuestAndPersistsNothing(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Gated', 'gatedForm', 'Gated');
        $form->requireLogin = true;
        Craft::$app->getElements()->saveElement($form);

        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        // No userId in context = anonymous.
        $result = $this->service()->submit($form, ['field_' . $fieldId => 'hi'], [
            'skipCaptcha' => true,
        ]);

        $this->assertNull($result['submission']);
        $this->assertArrayHasKey('form', (array) $result['errors']);
        $this->assertSame(
            (int) $before,
            (int) (new Query())->from('{{%simpleform_submissions}}')->count(),
            'No row should persist for a rejected anonymous submission',
        );
    }

    public function testRequireLoginAllowsLoggedInUserAndAssociatesUserId(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Members', 'membersForm', 'Members');
        $form->requireLogin = true;
        Craft::$app->getElements()->saveElement($form);

        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);
        $userId = $this->seedUser('member@example.com');

        $result = $this->service()->submit($form, ['field_' . $fieldId => 'hello'], [
            'skipCaptcha' => true,
            'userId' => $userId,
        ]);

        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);
        $this->assertSame($userId, (int) $result['submission']->userId);

        // Association round-trips from the DB.
        $reloaded = Submission::find()->id($result['submission']->id)->one();
        $this->assertSame($userId, (int) $reloaded->userId);
    }

    public function testPerUserLimitBlocksSecondSubmissionButAllowsAnotherUser(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Vote', 'voteForm', 'Vote');
        $form->submissionsPerUser = 1;
        Craft::$app->getElements()->saveElement($form);

        $fieldId = $this->createField($form->id, 'text', 'choice', 'Choice', false);
        $userA = $this->seedUser('a@example.com');
        $userB = $this->seedUser('b@example.com');

        // First submission by A succeeds.
        $first = $this->service()->submit($form, ['field_' . $fieldId => 'yes'], [
            'skipCaptcha' => true,
            'userId' => $userA,
        ]);
        $this->assertInstanceOf(Submission::class, $first['submission']);

        // Second by A is rejected with the limit message.
        $second = $this->service()->submit($form, ['field_' . $fieldId => 'no'], [
            'skipCaptcha' => true,
            'userId' => $userA,
        ]);
        $this->assertNull($second['submission']);
        $this->assertSame([$form->getUserLimitMessage()], $second['errors']['form']);

        // A fresh user can still submit once.
        $other = $this->service()->submit($form, ['field_' . $fieldId => 'yes'], [
            'skipCaptcha' => true,
            'userId' => $userB,
        ]);
        $this->assertInstanceOf(Submission::class, $other['submission']);
    }

    public function testSpamRowDoesNotCountTowardCap(): void
    {
        $this->requireCraft();

        $form = $this->createForm('OneShot', 'oneShotForm', 'One shot');
        $form->submissionsPerUser = 1;
        Craft::$app->getElements()->saveElement($form);

        $fieldId = $this->createField($form->id, 'text', 'note', 'Note', false);
        $userId = $this->seedUser('spammer@example.com');

        // Seed a spam submission directly — it must not burn the user's allowance.
        $spam = new Submission();
        $spam->formId = (int) $form->id;
        $spam->siteId = (int) Craft::$app->getSites()->getPrimarySite()->id;
        $spam->userId = $userId;
        $spam->data = ['field_' . $fieldId => ['label' => 'Note', 'type' => 'text', 'value' => 'x']];
        $spam->readStatus = 'spam';
        $spam->spamReason = 'akismet';
        Craft::$app->getElements()->saveElement($spam);

        // The user can still submit a real entry.
        $result = $this->service()->submit($form, ['field_' . $fieldId => 'real'], [
            'skipCaptcha' => true,
            'userId' => $userId,
        ]);
        $this->assertInstanceOf(Submission::class, $result['submission']);
    }

    public function testGuestEmailKeyingBlocksRepeatAndNoneNeverLimits(): void
    {
        $this->requireCraft();

        // guestLimitKey = email → second submission with the same email is blocked.
        $form = $this->createForm('Survey', 'surveyForm', 'Survey');
        $form->submissionsPerUser = 1;
        $form->guestLimitKey = Form::GUEST_LIMIT_EMAIL;
        Craft::$app->getElements()->saveElement($form);

        $emailFieldId = $this->createField($form->id, 'email', 'email', 'Email', true);

        $first = $this->service()->submit($form, ['field_' . $emailFieldId => 'guest@example.com'], [
            'skipCaptcha' => true,
        ]);
        $this->assertInstanceOf(Submission::class, $first['submission']);

        $second = $this->service()->submit($form, ['field_' . $emailFieldId => 'guest@example.com'], [
            'skipCaptcha' => true,
        ]);
        $this->assertNull($second['submission'], 'Same guest email should be blocked');

        // A different email is allowed.
        $third = $this->service()->submit($form, ['field_' . $emailFieldId => 'other@example.com'], [
            'skipCaptcha' => true,
        ]);
        $this->assertInstanceOf(Submission::class, $third['submission']);

        // guestLimitKey = none → guests are never limited.
        $open = $this->createForm('Open', 'openForm', 'Open');
        $open->submissionsPerUser = 1;
        $open->guestLimitKey = Form::GUEST_LIMIT_NONE;
        Craft::$app->getElements()->saveElement($open);

        $openEmailId = $this->createField($open->id, 'email', 'email', 'Email', true);

        $r1 = $this->service()->submit($open, ['field_' . $openEmailId => 'same@example.com'], ['skipCaptcha' => true]);
        $r2 = $this->service()->submit($open, ['field_' . $openEmailId => 'same@example.com'], ['skipCaptcha' => true]);
        $this->assertInstanceOf(Submission::class, $r1['submission']);
        $this->assertInstanceOf(Submission::class, $r2['submission'], 'guestLimitKey=none never limits guests');
    }

    public function testFormSettingsRoundTripThroughDb(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Settings', 'settingsForm', 'Settings');
        $form->requireLogin = true;
        $form->submissionsPerUser = 3;
        $form->guestLimitKey = Form::GUEST_LIMIT_EMAIL;
        $form->loginRequiredMessage = 'Members only.';
        $form->userLimitMessage = 'No more entries.';
        Craft::$app->getElements()->saveElement($form);

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertTrue($reloaded->requireLogin);
        $this->assertSame(3, $reloaded->submissionsPerUser);
        $this->assertSame(Form::GUEST_LIMIT_EMAIL, $reloaded->guestLimitKey);
        $this->assertSame('Members only.', $reloaded->loginRequiredMessage);
        $this->assertSame('No more entries.', $reloaded->userLimitMessage);
    }

    private function seedUser(string $email): int
    {
        $user = new User();
        $user->email = $email;
        $user->username = $email;
        $this->assertTrue(
            Craft::$app->getElements()->saveElement($user),
            'User should save: ' . implode(', ', $user->getFirstErrors()),
        );

        return (int) $user->id;
    }

    private function service(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }
}

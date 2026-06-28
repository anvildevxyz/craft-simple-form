<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use Craft;
use craft\elements\User;
use SmokeTester;

/**
 * Login-required + per-user submission limit Smoke Tests (#135, functional).
 *
 * Covers the visitor-facing behaviour through the real render + submit paths: a
 * login-required form hides itself behind the login-required notice for a guest
 * and rejects an anonymous submission; a per-user cap shows the limit notice to
 * a user who has hit it and rejects their over-cap submission. Forms and fields
 * are seeded through the data layer (see {@see BaseSmokeCest}).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class UserLimitsCest extends BaseSmokeCest
{
    // =========================================================================
    // PRIVATE PROPERTIES
    // =========================================================================

    private int $formId;

    private string $formHandle;

    private int $fieldId;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $form = $this->createForm('User Limits Test', 'userLimit' . uniqid(), 'admin@test.com');
        $this->formId = (int)$form->id;
        $this->formHandle = $form->handle;
        $this->fieldId = $this->createField($this->formId, 'text', 'note', 'Note');
    }

    public function testLoginRequiredRejectsGuestPost(SmokeTester $I): void
    {
        // The render-time login-required notice builds a login link from the
        // current request's absolute URL, which the console-booted test SAPI can't
        // resolve — that branch is covered by the Playwright scenarios. Here we
        // assert the server-side gate: a crafted anonymous submission is rejected
        // and persists nothing, which is the security-critical behaviour.
        $form = $this->form();
        $form->requireLogin = true;
        Craft::$app->getElements()->saveElement($form);

        $before = Submission::find()->formId($this->formId)->siteId('*')->status(null)->count();
        $result = $this->service()->submit($form, [$this->fieldId => 'hi'], ['skipCaptcha' => true]);
        $I->assertNull($result['submission'], 'Anonymous post to a login-required form must be rejected');
        $I->assertArrayHasKey('form', (array)$result['errors']);
        $after = Submission::find()->formId($this->formId)->siteId('*')->status(null)->count();
        $I->assertSame($before, $after, 'No row persisted for a rejected anonymous submission');
    }

    public function testPerUserCapShowsLimitNoticeAndRejectsOverCapPost(SmokeTester $I): void
    {
        $form = $this->form();
        $form->submissionsPerUser = 1;
        Craft::$app->getElements()->saveElement($form);

        $userId = $this->seedUser('member-' . uniqid() . '@example.com');

        $this->asUser($userId, function() use ($I, $form, $userId): void {
            $service = $this->service();

            // First submission fills the cap (attributed to the active user).
            $first = $service->submit($form, [$this->fieldId => 'first'], [
                'skipCaptcha' => true,
                'userId' => $userId,
            ]);
            $I->assertInstanceOf(Submission::class, $first['submission']);

            // The render now shows the limit notice instead of the form.
            $html = $this->renderForm($this->formHandle);
            $I->assertStringContainsString('simple-form--limit-reached', $html, 'Capped user sees the limit notice');
            $I->assertStringNotContainsString('<form', $html, 'No form element once the cap is reached');

            // A second submission from the same user is rejected.
            $second = $service->submit($form, [$this->fieldId => 'second'], [
                'skipCaptcha' => true,
                'userId' => $userId,
            ]);
            $I->assertNull($second['submission'], 'Over-cap submission rejected');
            $I->assertArrayHasKey('form', (array)$second['errors']);
        });
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * The form element reloaded fresh from the current site.
     */
    private function form(): Form
    {
        return Form::find()->id($this->formId)->siteId(Craft::$app->getSites()->getPrimarySite()->id)->one();
    }

    /**
     * Seed a bare user and return its id.
     */
    private function seedUser(string $email): int
    {
        $user = new User();
        $user->email = $email;
        $user->username = $email;
        Craft::$app->getElements()->saveElement($user);

        return (int)$user->id;
    }

    /**
     * Run a closure with the given user as the active identity, restoring the
     * prior (guest) identity afterwards.
     */
    private function asUser(int $userId, callable $fn): void
    {
        $userSession = Craft::$app->getUser();
        $previous = $userSession->getIdentity();
        try {
            $userSession->setIdentity(User::find()->id($userId)->one());
            $fn();
        } finally {
            $userSession->setIdentity($previous);
        }
    }
}

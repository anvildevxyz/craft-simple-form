<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\StringHelper;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * Server-side capture of Hidden fields (#124) through the real submission entry
 * point — the single path shared by the front-end controller and the GraphQL
 * mutation.
 *
 * The load-bearing assertion: for a `user` source the server re-resolves the
 * value from the authenticated identity and ignores a spoofed posted value, so
 * a forged hidden input cannot impersonate another user. Pass-through sources
 * (query/static) capture the sanitized posted value.
 *
 * @group requires-craft
 */
class HiddenFieldSubmissionTest extends SimpleFormTestCase
{
    public function testQuerySourcedHiddenValueIsCaptured(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Hidden Query', 'hiddenQueryForm', 'Hidden Query');
        $utm = $this->createField($form->id, 'hidden', 'utmSource', 'UTM Source', false, [
            'source' => 'query',
            'queryParam' => 'utm_source',
        ]);

        $result = $this->submit($form, [
            $utm => 'spring-sale', // the value the rendered hidden input posted
        ]);

        $this->assertNull($result['errors']);
        $submission = $result['submission'];
        $this->assertInstanceOf(Submission::class, $submission);

        $data = $this->storedData((int) $submission->id);
        $this->assertSame('spring-sale', $data['field_' . $utm]['value']);
        $this->assertSame('UTM Source', $data['field_' . $utm]['label']);
        $this->assertSame('hidden', $data['field_' . $utm]['type']);
    }

    public function testUserSourcedHiddenValueIgnoresSpoofedPostAndUsesAuthenticatedEmail(): void
    {
        $this->requireCraft();

        $user = $this->createUser();

        $form = $this->createForm('Hidden User', 'hiddenUserForm', 'Hidden User');
        $emailField = $this->createField($form->id, 'hidden', 'memberEmail', 'Member Email', false, [
            'source' => 'user',
            'userAttribute' => 'email',
        ]);

        // The visitor forges a different email in the hidden input.
        $result = $this->submit($form, [
            $emailField => 'attacker@evil.test',
        ], (int) $user->id);

        $this->assertNull($result['errors']);
        $submission = $result['submission'];
        $this->assertInstanceOf(Submission::class, $submission);

        $data = $this->storedData((int) $submission->id);
        $this->assertSame(
            $user->email,
            $data['field_' . $emailField]['value'],
            'A user-sourced hidden field must store the authenticated email, never the spoofed POST value.',
        );
        $this->assertNotSame('attacker@evil.test', $data['field_' . $emailField]['value']);
    }

    public function testUserSourcedHiddenValueForGuestFallsBackToDefault(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Hidden Guest', 'hiddenGuestForm', 'Hidden Guest');
        $emailField = $this->createField($form->id, 'hidden', 'memberEmail', 'Member Email', false, [
            'source' => 'user',
            'userAttribute' => 'email',
            'default' => 'guest',
        ]);

        // No userId in context = guest.
        $result = $this->submit($form, [
            $emailField => 'attacker@evil.test',
        ]);

        $this->assertNull($result['errors']);
        $data = $this->storedData((int) $result['submission']->id);
        $this->assertSame('guest', $data['field_' . $emailField]['value']);
    }

    /**
     * Submit through the transport-agnostic entry point with captcha skipped
     * (the integration env has no captcha), exercising the same capture path as
     * the controller + GraphQL mutation.
     *
     * @param array<int, mixed> $valuesByFieldId
     * @return array{submission: Submission|null, errors: array<string, mixed>|null}
     */
    private function submit(\fabianhaef\simpleform\elements\Form $form, array $valuesByFieldId, ?int $userId = null): array
    {
        return $this->submissionService()->submit($form, $valuesByFieldId, [
            'honeypot' => '',
            'skipCaptcha' => true,
            'userId' => $userId,
        ]);
    }

    private function createUser(): User
    {
        $user = new User();
        $user->username = 'member_' . StringHelper::randomString(6);
        $user->email = $user->username . '@example.test';
        $saved = Craft::$app->getElements()->saveElement($user);
        $this->assertTrue($saved, 'User should save: ' . implode(', ', $user->getFirstErrors()));

        return $user;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function storedData(int $submissionId): array
    {
        $row = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $submissionId])
            ->one();

        $data = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);

        return is_array($data) ? $data : [];
    }

    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');

        return $service;
    }
}

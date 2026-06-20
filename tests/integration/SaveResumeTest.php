<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\web\Response;
use fabianhaef\simpleform\controllers\SubmitController;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\TwigExtension;

/**
 * Save-&-resume: draft storage/lifecycle, the per-form opt-in, the save-draft
 * endpoint, and prefill-on-resume rendering.
 *
 * @group requires-craft
 */
class SaveResumeTest extends SimpleFormTestCase
{
    private const TABLE = '{{%simpleform_form_drafts}}';

    private function resumableForm(string $handle): Form
    {
        $form = $this->createForm('Resume', $handle);
        $form->allowSaveResume = true;
        $this->assertTrue(Craft::$app->getElements()->saveElement($form));
        return $form;
    }

    public function testAllowSaveResumePersistsOnTheForm(): void
    {
        $this->requireCraft();
        $form = $this->resumableForm('persist_resume');

        $reloaded = Form::find()->id($form->id)->one();
        $this->assertTrue($reloaded->allowSaveResume);
    }

    public function testDraftSaveGetScopeAndDelete(): void
    {
        $this->requireCraft();
        $drafts = Plugin::getInstance()->getDrafts();
        $form = $this->resumableForm('draft_roundtrip');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $token = $drafts->save((int) $form->id, $siteId, ['field_1' => 'Ada', 'field_2' => 'ada@example.test']);
        $this->assertNotSame('', $token);

        $data = $drafts->getData($token, (int) $form->id);
        $this->assertSame(['field_1' => 'Ada', 'field_2' => 'ada@example.test'], $data);

        // Wrong form id, unknown token → null.
        $this->assertNull($drafts->getData($token, (int) $form->id + 999));
        $this->assertNull($drafts->getData('nope', (int) $form->id));

        // The plaintext token is never stored.
        $this->assertSame(0, (int) (new Query())->from(self::TABLE)->where(['data' => $token])->count());

        $drafts->delete($token);
        $this->assertNull($drafts->getData($token, (int) $form->id));
    }

    public function testReSaveWithSameTokenUpdatesInPlace(): void
    {
        $this->requireCraft();
        $drafts = Plugin::getInstance()->getDrafts();
        $form = $this->resumableForm('draft_update');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $token = $drafts->save((int) $form->id, $siteId, ['field_1' => 'first']);
        $same = $drafts->save((int) $form->id, $siteId, ['field_1' => 'second'], $token);

        $this->assertSame($token, $same);
        $this->assertSame(['field_1' => 'second'], $drafts->getData($token, (int) $form->id));
        $this->assertSame(1, (int) (new Query())->from(self::TABLE)->where(['formId' => $form->id])->count());
    }

    public function testExpiredDraftsAreNotReturnedAndAreGarbageCollected(): void
    {
        $this->requireCraft();
        $drafts = Plugin::getInstance()->getDrafts();
        $form = $this->resumableForm('draft_expiry');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $token = $drafts->save((int) $form->id, $siteId, ['field_1' => 'stale']);

        // Force it into the past.
        Craft::$app->getDb()->createCommand()->update(
            self::TABLE,
            ['dateExpires' => Db::prepareDateForDb((new \DateTime())->modify('-1 day'))],
            ['formId' => $form->id],
        )->execute();

        $this->assertNull($drafts->getData($token, (int) $form->id));
        $this->assertSame(1, $drafts->gcExpired());
        $this->assertSame(0, (int) (new Query())->from(self::TABLE)->where(['formId' => $form->id])->count());
    }

    public function testSaveDraftControllerStoresValuesAndRequiresOptIn(): void
    {
        $this->requireCraft();
        $resumable = $this->resumableForm('ctrl_resume');
        $plain = $this->createForm('Plain', 'ctrl_plain'); // allowSaveResume defaults false

        $token = $this->callSaveDraft('ctrl_resume', ['field_5' => 'hello', 'notAField' => 'x']);
        $this->assertNotNull($token);
        // Only field_* values are stored.
        $this->assertSame(['field_5' => 'hello'], Plugin::getInstance()->getDrafts()->getData($token, (int) $resumable->id));

        // A form that didn't opt in returns no token.
        $this->assertNull($this->callSaveDraft('ctrl_plain', ['field_5' => 'hello']));
    }

    public function testRenderPrefillsSavedValuesOnResume(): void
    {
        $this->requireCraft();
        $form = $this->resumableForm('prefill_resume');
        $fieldId = $this->createField($form->id, 'text', 'fullName', 'Full Name', false);
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $token = Plugin::getInstance()->getDrafts()->save((int) $form->id, $siteId, ['field_' . $fieldId => 'Grace Hopper']);

        Craft::$app->getRequest()->setQueryParams(['sfresume' => $token]);
        try {
            $html = (new TwigExtension())->renderForm('prefill_resume');
        } finally {
            Craft::$app->getRequest()->setQueryParams([]);
        }

        $this->assertStringContainsString('Grace Hopper', $html);
    }

    public function testMultiStepResumableFormShowsSaveButton(): void
    {
        $this->requireCraft();
        $form = $this->resumableForm('savebtn_resume');
        $this->createField($form->id, 'text', 'name', 'Name', false, ['page' => 1]);
        $this->createField($form->id, 'email', 'email', 'Email', false, ['page' => 2]);

        $html = (new TwigExtension())->renderForm('savebtn_resume');
        $this->assertStringContainsString('simple-form-save-resume', $html);
        $this->assertStringContainsString('data-sf-resume=', $html);
    }

    /** @param array<string, mixed> $fields @return string|null the token, or null on failure */
    private function callSaveDraft(string $handle, array $fields): ?string
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => $handle] + $fields);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new SubmitController('submit', Plugin::getInstance());
        $controller->enableCsrfValidation = false;
        $data = $controller->actionSaveDraft()->data;

        return (is_array($data) && ($data['success'] ?? false)) ? (string) $data['token'] : null;
    }
}

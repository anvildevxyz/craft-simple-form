<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\TwigExtension;
use Craft;
use craft\db\Query;
use SmokeTester;

/**
 * Save-and-resume smoke tests (functional).
 *
 * Covers draft persistence, the save-draft controller endpoint, resume prefill
 * rendering, and opt-in gating on the form.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class SaveResumeSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testDraftRoundTripThroughService(SmokeTester $I): void
    {
        $form = $this->resumableForm('draft' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $drafts = Plugin::getInstance()->getDrafts();

        $token = $drafts->save((int) $form->id, $siteId, ['field_' . $fieldId => 'Ada Lovelace']);
        $I->assertNotSame('', $token);
        $I->assertSame(['field_' . $fieldId => 'Ada Lovelace'], $drafts->getData($token, (int) $form->id));

        $drafts->delete($token);
        $I->assertNull($drafts->getData($token, (int) $form->id));
    }

    public function testSaveDraftEndpointStoresFieldValues(SmokeTester $I): void
    {
        $form = $this->resumableForm('ctrl' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'message', 'Message');

        $token = $this->callSaveDraft($form->handle, ['field_' . $fieldId => 'partial answer']);
        $I->assertNotNull($token);

        $stored = Plugin::getInstance()->getDrafts()->getData($token, (int) $form->id);
        $I->assertSame(['field_' . $fieldId => 'partial answer'], $stored);
    }

    public function testSaveDraftRequiresFormOptIn(SmokeTester $I): void
    {
        $form = $this->createForm('Plain', 'plain' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $token = $this->callSaveDraft($form->handle, ['field_' . $fieldId => 'hello']);
        $I->assertNull($token);
    }

    public function testSaveDraftIsRateLimited(SmokeTester $I): void
    {
        $this->resetSubmitRateLimitForCurrentIp();
        $form = $this->resumableForm('rate' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        Plugin::getInstance()->getSettings()->submitRateLimitPerMinute = 1;

        $first = $this->callSaveDraft($form->handle, ['field_' . $fieldId => 'a']);
        $second = $this->callSaveDraft($form->handle, ['field_' . $fieldId => 'b']);

        $I->assertNotNull($first);
        $I->assertNull($second);
    }

    public function testResumePrefillsRenderedForm(SmokeTester $I): void
    {
        $form = $this->resumableForm('prefill' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'fullName', 'Full Name');
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $token = Plugin::getInstance()->getDrafts()->save(
            (int) $form->id,
            $siteId,
            ['field_' . $fieldId => 'Grace Hopper'],
        );

        Craft::$app->getRequest()->setQueryParams(['sfresume' => $token]);
        try {
            $html = (new TwigExtension())->renderForm($form->handle);
        } finally {
            Craft::$app->getRequest()->setQueryParams([]);
        }

        $I->assertStringContainsString('Grace Hopper', $html);
    }

    public function testPlaintextTokenIsNeverStored(SmokeTester $I): void
    {
        $form = $this->resumableForm('hash' . uniqid());
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $token = Plugin::getInstance()->getDrafts()->save((int) $form->id, $siteId, ['field_1' => 'x']);

        $count = (int) (new Query())
            ->from('{{%simpleform_form_drafts}}')
            ->where(['data' => $token])
            ->count();
        $I->assertSame(0, $count);
    }

    // =========================================================================
    // PROTECTED METHODS
    // =========================================================================

    protected function resumableForm(string $handle): Form
    {
        $form = $this->createForm('Resume', $handle);
        $form->allowSaveResume = true;
        Craft::$app->getElements()->saveElement($form);

        return $form;
    }
}

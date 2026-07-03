<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\models\ImportResult;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\FormPortabilityService;
use Craft;

/**
 * #226 — full-fidelity form settings in the portable document. Every form-level
 * setting must export, round-trip on create and update, and a v1 document
 * (without the settings block) must preserve the form's existing settings.
 *
 * @group requires-craft
 */
class FullFidelityExportTest extends SimpleFormTestCase
{
    private function service(): FormPortabilityService
    {
        return Plugin::getInstance()->getPortability();
    }

    private function seedWithSettings(string $handle): Form
    {
        $form = $this->createForm('Full Fidelity', $handle);
        $form->requireLogin = true;
        $form->submissionsPerUser = 3;
        $form->submissionLimit = 100;
        $form->guestLimitKey = Form::GUEST_LIMIT_EMAIL;
        $form->allowEditing = true;
        $form->editWindowMinutes = 60;
        $form->preventDuplicates = true;
        $form->duplicateWindowMinutes = 30;
        $form->duplicateKey = Form::DUPLICATE_KEY_IP;
        $form->useCustomTemplate = true;
        $form->templatePath = '_my/forms';
        $form->renderMode = 'conversational';
        $form->quizMode = true;
        $form->quizGradeBands = "90 Excellent\n50 Pass";
        $form->autoCaptureAttribution = true;
        $form->capturePartials = true;
        $form->openDate = new \DateTime('2026-01-01T00:00:00+00:00');
        $form->closeDate = new \DateTime('2026-12-31T00:00:00+00:00');
        Craft::$app->getElements()->saveElement($form);

        return Form::find()->id($form->id)->siteId('*')->status(null)->one();
    }

    public function testExportIncludesAllSettings(): void
    {
        $this->requireCraft();
        $doc = $this->service()->export($this->seedWithSettings('ff_export'));
        $f = $doc['form'];

        $this->assertSame(2, $doc['_meta']['schemaVersion']);
        $this->assertTrue($f['requireLogin']);
        $this->assertSame(3, $f['submissionsPerUser']);
        $this->assertSame(100, $f['submissionLimit']);
        $this->assertSame(Form::GUEST_LIMIT_EMAIL, $f['guestLimitKey']);
        $this->assertTrue($f['allowEditing']);
        $this->assertSame(60, $f['editWindowMinutes']);
        $this->assertTrue($f['preventDuplicates']);
        $this->assertSame(30, $f['duplicateWindowMinutes']);
        $this->assertSame(Form::DUPLICATE_KEY_IP, $f['duplicateKey']);
        $this->assertTrue($f['useCustomTemplate']);
        $this->assertSame('_my/forms', $f['templatePath']);
        $this->assertSame('conversational', $f['renderMode']);
        $this->assertTrue($f['quizMode']);
        $this->assertSame("90 Excellent\n50 Pass", $f['quizGradeBands']);
        $this->assertTrue($f['autoCaptureAttribution']);
        $this->assertTrue($f['capturePartials']);
        $this->assertNotEmpty($f['openDate']);
        $this->assertNotEmpty($f['closeDate']);
    }

    public function testCreateRoundTripsSettings(): void
    {
        $this->requireCraft();
        $src = $this->seedWithSettings('ff_src');
        $doc = $this->service()->export($src);
        $doc['form']['handle'] = 'ff_clone';

        $result = $this->service()->import($doc, ['mode' => FormPortabilityService::MODE_ABORT]);
        $clone = Form::find()->id($result->form->id)->siteId('*')->status(null)->one();

        $this->assertTrue($clone->requireLogin);
        $this->assertSame(3, $clone->submissionsPerUser);
        $this->assertSame(100, $clone->submissionLimit);
        $this->assertSame(Form::DUPLICATE_KEY_IP, $clone->duplicateKey);
        $this->assertSame(30, $clone->duplicateWindowMinutes);
        $this->assertTrue($clone->allowEditing);
        $this->assertSame('_my/forms', $clone->templatePath);
        $this->assertSame('conversational', $clone->renderMode);
        $this->assertTrue($clone->quizMode);
        $this->assertSame("90 Excellent\n50 Pass", $clone->quizGradeBands);
        $this->assertTrue($clone->autoCaptureAttribution);
        $this->assertTrue($clone->capturePartials);
        // Compare the instant (timezone-agnostic): the same moment round-trips.
        $this->assertSame($src->openDate?->getTimestamp(), $clone->openDate?->getTimestamp());
        $this->assertSame($src->closeDate?->getTimestamp(), $clone->closeDate?->getTimestamp());
    }

    public function testUpdateAppliesChangedSettings(): void
    {
        $this->requireCraft();
        $form = $this->seedWithSettings('ff_update');
        $doc = $this->service()->export($form);

        // Edit settings in the file, then apply onto the existing form.
        $doc['form']['requireLogin'] = false;
        $doc['form']['submissionLimit'] = null;
        $doc['form']['duplicateKey'] = Form::DUPLICATE_KEY_CONTENT;
        $this->service()->applyToExistingForm($form, $doc, false, new ImportResult());

        $reloaded = Form::find()->id($form->id)->siteId('*')->status(null)->one();
        $this->assertFalse($reloaded->requireLogin);
        $this->assertNull($reloaded->submissionLimit);
        $this->assertSame(Form::DUPLICATE_KEY_CONTENT, $reloaded->duplicateKey);
    }

    public function testV1DocumentPreservesSettingsOnUpdate(): void
    {
        $this->requireCraft();
        $form = $this->seedWithSettings('ff_v1');
        $doc = $this->service()->export($form);

        // Simulate an old (v1) document: strip the settings block + downgrade meta.
        foreach ([
            'postSubmitAction', 'redirectEntry', 'openDate', 'closeDate', 'submissionLimit',
            'submissionsPerUser', 'requireLogin', 'guestLimitKey', 'allowEditing',
            'editWindowMinutes', 'preventDuplicates', 'duplicateWindowMinutes',
            'duplicateKey', 'useCustomTemplate', 'templatePath',
            'renderMode', 'quizMode', 'quizGradeBands', 'autoCaptureAttribution', 'capturePartials',
        ] as $key) {
            unset($doc['form'][$key]);
        }
        $doc['_meta']['schemaVersion'] = 1;

        $this->service()->applyToExistingForm($form, $doc, false, new ImportResult());

        // The v1 file lacks the keys, so existing settings must be preserved.
        $reloaded = Form::find()->id($form->id)->siteId('*')->status(null)->one();
        $this->assertTrue($reloaded->requireLogin, 'v1 doc must not reset settings');
        $this->assertSame(3, $reloaded->submissionsPerUser);
        $this->assertSame(Form::DUPLICATE_KEY_IP, $reloaded->duplicateKey);
        $this->assertSame('conversational', $reloaded->renderMode, 'v1 doc must not reset renderMode');
        $this->assertTrue($reloaded->quizMode, 'v1 doc must not reset quizMode');
    }

    /**
     * Regression guard: the conversational/quiz/attribution/partial-capture
     * toggles (renderMode, quizMode, quizGradeBands, autoCaptureAttribution,
     * capturePartials) must survive a JSON export → re-import round-trip onto a
     * fresh form. Previously they were absent from both the export document and
     * applyFormSettings(), so a re-import silently reset them to their defaults.
     */
    public function testConversationalQuizSettingsRoundTripThroughJson(): void
    {
        $this->requireCraft();
        $src = $this->seedWithSettings('ff_cq_src');

        // Round-trip through the actual JSON string (the forms-as-code path).
        $json = $this->service()->exportJson($src);
        $doc = json_decode($json, true);
        $doc['form']['handle'] = 'ff_cq_clone';

        $result = $this->service()->import($doc, ['mode' => FormPortabilityService::MODE_ABORT]);
        $clone = Form::find()->id($result->form->id)->siteId('*')->status(null)->one();

        $this->assertSame('conversational', $clone->renderMode);
        $this->assertTrue($clone->quizMode);
        $this->assertSame("90 Excellent\n50 Pass", $clone->quizGradeBands);
        $this->assertTrue($clone->autoCaptureAttribution);
        $this->assertTrue($clone->capturePartials);
    }

    public function testUnresolvableRedirectEntryWarnsAndUnsets(): void
    {
        $this->requireCraft();
        $doc = $this->service()->export($this->seedWithSettings('ff_redir'));
        $doc['form']['handle'] = 'ff_redir_clone';
        $doc['form']['postSubmitAction'] = 'entry';
        $doc['form']['redirectEntry'] = ['uri' => 'does/not/exist/anywhere'];

        $result = $this->service()->import($doc, ['mode' => FormPortabilityService::MODE_ABORT]);
        $clone = Form::find()->id($result->form->id)->siteId('*')->status(null)->one();

        $this->assertNull($clone->redirectEntryId, 'an unresolvable redirect entry is left unset');
        $this->assertNotEmpty(array_filter(
            $result->warnings,
            static fn(string $w): bool => str_contains($w, 'does/not/exist/anywhere'),
        ), 'a warning is recorded for the missing entry');
    }
}

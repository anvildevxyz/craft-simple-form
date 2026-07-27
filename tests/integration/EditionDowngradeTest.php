<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\Editions;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\TwigExtension;

/**
 * The edition gate governs *authoring*, never *runtime*: a form built on Pro
 * that contains a Pro field must keep rendering after a downgrade to Solo, and
 * the save-time escalation guard must reflect whatever edition is active.
 *
 * @group requires-craft
 */
class EditionDowngradeTest extends SimpleFormTestCase
{
    private ?string $originalEdition = null;

    protected function tearDown(): void
    {
        if ($this->originalEdition !== null) {
            Plugin::getInstance()->edition = $this->originalEdition;
            $this->originalEdition = null;
        }
        parent::tearDown();
    }

    private function setEdition(string $edition): void
    {
        $plugin = Plugin::getInstance();
        $this->originalEdition ??= $plugin->edition;
        $plugin->edition = $edition;
    }

    public function testProFieldStillRendersUnderSolo(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Feedback', 'feedbackForm', 'Feedback');
        $this->createField($form->id, 'text', 'msg', 'Message', true);
        $ratingId = $this->createField($form->id, 'rating', 'score', 'Score', false, ['max' => 5]);

        // Downgrade the live edition to Solo, then render.
        $this->setEdition(Editions::SOLO);
        $this->assertFalse(Editions::isStandard());

        $html = (new TwigExtension())->renderForm('feedbackForm');

        $this->assertStringContainsString('<form', $html);
        // The Pro rating field is untouched by the gate and still renders.
        $this->assertStringContainsString('field_' . $ratingId, $html);
        $this->assertStringContainsString('Score', $html);
    }

    public function testProIntegrationTypeStillResolvesUnderSolo(): void
    {
        $this->requireCraft();

        $this->setEdition(Editions::SOLO);
        $registry = Plugin::getInstance()->getIntegrationTypeRegistry();

        // Runtime resolution is edition-blind, so an existing Slack integration on
        // a downgraded install keeps dispatching even though it can't be re-added.
        $this->assertNotNull($registry->getType('slack'));
        $this->assertFalse(Editions::integrationAllowed('slack'));
        $this->assertTrue(Editions::integrationAllowed('webhook'));
    }

    public function testConditionalAndMultiPageFormStillRendersUnderSolo(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Survey', 'surveyForm', 'Survey');
        $this->createField($form->id, 'select', 'plan', 'Plan', true, [
            'options' => [['label' => 'Pro', 'value' => 'pro'], ['label' => 'Free', 'value' => 'free']],
        ]);
        // A page-2 field with a conditional visibility rule — both are Pro caps.
        $detailsId = $this->createField($form->id, 'textarea', 'details', 'Details', false, [
            'page' => 2,
            'conditional' => ['rules' => [['field' => 'plan', 'operator' => 'eq', 'value' => 'pro']]],
        ]);

        $this->setEdition(Editions::SOLO);

        $html = (new TwigExtension())->renderForm('surveyForm');

        // The downgraded form still renders its conditional, page-2 field.
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('field_' . $detailsId, $html);
    }

    public function testProServicesAreInertUnderSolo(): void
    {
        $this->requireCraft();

        $audit = new \anvildev\simpleform\services\AuditService();
        $pdf = new \anvildev\simpleform\services\PdfService();

        $this->setEdition(Editions::SOLO);

        // PDF generation is off on Solo regardless of whether an engine is present.
        $this->assertFalse($pdf->isAvailable());

        // The generation path itself is gated too (not just the availability flag),
        // so a downgraded site never emails or stores a PDF for the same submission
        // the CP reports as Pro-only.
        $this->assertNull($pdf->render(new \anvildev\simpleform\elements\Form(), new \anvildev\simpleform\elements\Submission(), []));

        // The audit log is a no-op on Solo: logging adds no rows.
        $before = count($audit->recent(1000));
        $audit->log(
            \anvildev\simpleform\services\AuditService::ACTION_FORM_SAVE,
            \anvildev\simpleform\services\AuditService::TARGET_FORM,
            1,
            'solo-noop',
        );
        $this->assertCount($before, $audit->recent(1000));
    }

    public function testImportRejectsProFieldFormOnSoloButAllowsOnPro(): void
    {
        $this->requireCraft();

        $portability = new \anvildev\simpleform\services\FormPortabilityService();

        // Built as Pro (the harness default): a form carrying a Pro rating field.
        $form = $this->createForm('Imported', 'importedSrc', 'Imported');
        $this->createField($form->id, 'rating', 'score', 'Score', false, ['max' => 5]);
        $doc = $portability->export($form);

        // Pro: the same document imports fine.
        $proResult = $portability->import($doc, ['mode' => 'rename']);
        $this->assertNotNull($proResult->form);

        // Solo: importing a document that introduces a Pro field is rejected
        // (covers the CP-import, console-import, and forms-as-code paths).
        $this->setEdition(Editions::SOLO);
        $this->expectException(\yii\base\InvalidArgumentException::class);
        $portability->import($doc, ['mode' => 'rename']);
    }

    public function testEscalationGuardReflectsActiveEdition(): void
    {
        $this->requireCraft();

        $this->setEdition(Editions::SOLO);
        // Solo active: a brand-new Pro field is a blocked escalation; keeping an
        // already-present one is allowed.
        $this->assertSame(['rating'], Editions::blockedNewStandardFields(['text', 'rating'], []));
        $this->assertSame([], Editions::blockedNewStandardFields(['text', 'rating'], ['rating']));

        $this->setEdition(Editions::STANDARD);
        // Pro active: nothing is ever blocked.
        $this->assertSame([], Editions::blockedNewStandardFields(['text', 'rating'], []));
    }
}

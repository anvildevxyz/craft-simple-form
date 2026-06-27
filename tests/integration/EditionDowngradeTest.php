<?php

namespace fabianhaef\simpleform\tests\integration;

use fabianhaef\simpleform\Editions;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\TwigExtension;

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
        $this->assertFalse(Editions::isPro());

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

    public function testEscalationGuardReflectsActiveEdition(): void
    {
        $this->requireCraft();

        $this->setEdition(Editions::SOLO);
        // Solo active: a brand-new Pro field is a blocked escalation; keeping an
        // already-present one is allowed.
        $this->assertSame(['rating'], Editions::blockedNewProFields(['text', 'rating'], []));
        $this->assertSame([], Editions::blockedNewProFields(['text', 'rating'], ['rating']));

        $this->setEdition(Editions::PRO);
        // Pro active: nothing is ever blocked.
        $this->assertSame([], Editions::blockedNewProFields(['text', 'rating'], []));
    }
}

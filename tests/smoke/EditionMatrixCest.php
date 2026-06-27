<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Editions;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\PdfService;
use SmokeTester;

/**
 * Both-edition completeness check: the Solo and Pro editions are each correct
 * and complete for what they promise. Proves the core public submit path works
 * on Solo, the full path (incl. a Pro field) works on Pro, and the authoring /
 * service gating surface is right in both directions.
 *
 * The suite pins Pro by default (Helper\Integration); each test flips the edition
 * explicitly and {@see _after} restores it.
 */
class EditionMatrixCest extends BaseSmokeCest
{
    private ?string $originalEdition = null;

    public function _after(SmokeTester $I): void
    {
        if ($this->originalEdition !== null) {
            Plugin::getInstance()->edition = $this->originalEdition;
            $this->originalEdition = null;
        }
    }

    private function setEdition(string $edition): void
    {
        $plugin = Plugin::getInstance();
        $this->originalEdition ??= $plugin->edition;
        $plugin->edition = $edition;
    }

    /**
     * Completeness — Solo: a core contact form (the "better contact form" promise)
     * renders and accepts a submission end to end.
     */
    public function soloCoreContactFormSubmits(SmokeTester $I): void
    {
        $this->setEdition(Editions::SOLO);

        $form = $this->createForm('Solo Contact', 'soloContact');
        $nameId = $this->createField($form->id, 'text', 'fullName', 'Name', true);
        $emailId = $this->createField($form->id, 'email', 'email', 'Email', true);

        $result = $this->submitRequest('soloContact', [
            'field_' . $nameId => 'Ada Lovelace',
            'field_' . $emailId => 'ada@example.test',
        ]);

        $I->assertNotNull($result['submission'], 'Solo must accept a core contact-form submission');
        $I->assertNull($result['errors']);
    }

    /**
     * Completeness — Pro: a form carrying a Pro field (rating) submits end to end.
     */
    public function proFormWithProFieldSubmits(SmokeTester $I): void
    {
        $this->setEdition(Editions::PRO);

        $form = $this->createForm('Pro Survey', 'proSurvey');
        $emailId = $this->createField($form->id, 'email', 'email', 'Email', true);
        $ratingId = $this->createField($form->id, 'rating', 'score', 'Score', false, ['max' => 5]);

        $result = $this->submitRequest('proSurvey', [
            'field_' . $emailId => 'grace@example.test',
            'field_' . $ratingId => '4',
        ]);

        $I->assertNotNull($result['submission'], 'Pro must accept a form with a Pro field');
        $I->assertNull($result['errors']);
    }

    /**
     * The authoring + service gating surface is correct on Solo: Pro fields,
     * Pro integrations, Pro form-capabilities are all refused, and Pro services
     * are inert.
     */
    public function soloGatesTheProSurface(SmokeTester $I): void
    {
        $this->setEdition(Editions::SOLO);

        // Authoring gates.
        $I->assertNotEmpty(Editions::blockedNewProFields(['rating', 'payment'], []), 'Solo blocks Pro fields');
        $I->assertFalse(Editions::integrationAllowed('slack'), 'Solo blocks Pro integrations');
        $I->assertTrue(Editions::integrationAllowed('webhook'), 'Solo keeps the core integrations');
        $I->assertNotEmpty(
            Editions::blockedNewFormCapabilities(
                [['type' => 'text', 'config' => ['conditional' => ['rules' => [['field' => 'a']]]]]],
                true,
                [],
                false,
            ),
            'Solo blocks conditional logic + save-resume escalation',
        );

        // Service inertness.
        $I->assertFalse((new PdfService())->isAvailable(), 'PDF is unavailable on Solo');
    }

    /**
     * Pro unlocks the full authoring surface: nothing is gated.
     */
    public function proUnlocksTheFullSurface(SmokeTester $I): void
    {
        $this->setEdition(Editions::PRO);

        $I->assertSame([], Editions::blockedNewProFields(['rating', 'payment', 'repeater', 'signature'], []));
        $I->assertTrue(Editions::integrationAllowed('slack'));
        $I->assertTrue(Editions::integrationAllowed('hubspot'));
        $I->assertSame(
            [],
            Editions::blockedNewFormCapabilities(
                [['type' => 'text', 'config' => ['conditional' => ['rules' => [['field' => 'a']]], 'page' => 2]]],
                true,
                [],
                false,
            ),
        );
    }
}

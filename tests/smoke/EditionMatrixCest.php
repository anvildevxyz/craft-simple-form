<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\controllers\CouponsController;
use anvildev\simpleform\Editions;
use anvildev\simpleform\models\CouponModel;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\PdfService;
use Craft;
use craft\web\Response;
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

    /**
     * The post-1.0 batch's Pro form modes (#283) are gated at authoring on Solo:
     * conversational render, quiz scoring, and partial capture all block when
     * *introduced*. (Logic jumps + the approval workflow are Solo-free — see
     * {@see soloAllowsJumpsAndWorkflow}.)
     */
    public function soloGatesNewFormFeatures(SmokeTester $I): void
    {
        $this->setEdition(Editions::SOLO);

        // Scalar form modes: introducing any Pro mode from an all-off form is blocked.
        $blockedModes = Editions::blockedNewFormModes(
            [Editions::CAP_CONVERSATIONAL => true, Editions::CAP_QUIZ => true, Editions::CAP_PARTIAL_CAPTURE => true],
            [Editions::CAP_CONVERSATIONAL => false, Editions::CAP_QUIZ => false, Editions::CAP_PARTIAL_CAPTURE => false],
        );
        $I->assertSame(
            [Editions::CAP_CONVERSATIONAL, Editions::CAP_QUIZ, Editions::CAP_PARTIAL_CAPTURE],
            $blockedModes,
            'Solo blocks introducing conversational render, quiz scoring, and partial capture',
        );
    }

    /**
     * No-new-escalation: a Solo form that already uses these Pro features keeps
     * saving — only *newly* turning one on is refused.
     */
    public function soloPreservesExistingNewFormFeatures(SmokeTester $I): void
    {
        $this->setEdition(Editions::SOLO);

        // Scalar modes already on stay on (posted == stored, all on).
        $allOn = [Editions::CAP_CONVERSATIONAL => true, Editions::CAP_QUIZ => true, Editions::CAP_PARTIAL_CAPTURE => true];
        $I->assertSame([], Editions::blockedNewFormModes($allOn, $allOn), 'Solo preserves modes already on');
    }

    /**
     * Pro imposes none of the new gates.
     */
    public function proAllowsNewFormFeatures(SmokeTester $I): void
    {
        $this->setEdition(Editions::PRO);

        $I->assertSame(
            [],
            Editions::blockedNewFormModes(
                [Editions::CAP_CONVERSATIONAL => true, Editions::CAP_QUIZ => true, Editions::CAP_PARTIAL_CAPTURE => true],
                [],
            ),
        );
    }

    /**
     * Logic jumps and the submission approval workflow are Solo-free (#283 split
     * decision): neither is gated at authoring, so Solo may introduce a logic
     * jump and enable the approval workflow.
     */
    public function soloAllowsJumpsAndWorkflow(SmokeTester $I): void
    {
        $this->setEdition(Editions::SOLO);

        // A field carrying config.jumps is not a Pro escalation.
        $jumpItems = [['type' => 'select', 'config' => ['jumps' => [['target' => 'thanks', 'operator' => 'eq', 'value' => 'a']]]]];
        $I->assertSame(
            [],
            Editions::blockedNewFormCapabilities($jumpItems, false, [], false),
            'Solo may introduce a logic jump',
        );

        // enableWorkflow is not a Pro off-switch setting: Solo may turn it on.
        $I->assertFalse(
            Editions::blocksProSettingChange('enableWorkflow', false, true),
            'Solo may enable the approval workflow',
        );
    }

    /**
     * Coupons (#283) are Pro: Solo can't create a *new* one, but an existing
     * coupon keeps validating at checkout — the runtime stays edition-blind.
     */
    public function soloBlocksNewCouponsButKeepsExisting(SmokeTester $I): void
    {
        $coupons = Plugin::getInstance()->getCoupons();

        $this->setEdition(Editions::SOLO);

        // Attempt to CREATE a new coupon through the CP controller on Solo.
        $this->attemptCouponSave(['code' => 'SOLONEW', 'type' => CouponModel::TYPE_FIXED, 'amount' => 5, 'enabled' => 1]);
        $I->assertNull($coupons->getByCode('SOLONEW'), 'Solo must not create a new coupon');

        // A coupon that already existed (as a pre-downgrade one would) still
        // validates at checkout — the save path is gated, evaluation is not.
        $existing = new CouponModel();
        $existing->code = 'KEEP10';
        $existing->type = CouponModel::TYPE_FIXED;
        $existing->amount = 10;
        $existing->enabled = true;
        $I->assertTrue($coupons->save($existing), 'Seeding an existing coupon should succeed');

        $result = $coupons->evaluate('KEEP10', 100.0);
        $I->assertNotNull($result['coupon'], 'An existing coupon still validates on Solo');
        $I->assertNull($result['error']);
    }

    /**
     * Pro can create coupons through the same controller path.
     */
    public function proAllowsCreatingCoupons(SmokeTester $I): void
    {
        $this->setEdition(Editions::PRO);

        $this->attemptCouponSave(['code' => 'PRONEW', 'type' => CouponModel::TYPE_FIXED, 'amount' => 5, 'enabled' => 1]);
        $I->assertNotNull(
            Plugin::getInstance()->getCoupons()->getByCode('PRONEW'),
            'Pro creates a new coupon',
        );
    }

    /**
     * Drive CouponsController::actionSave directly (mirroring how the base Cest
     * drives SubmitController) with a POST body. The Solo block path re-renders a
     * CP template that need not resolve under the console-booted test app, so any
     * render throwable is swallowed — the caller's persistence assertion is the
     * real check.
     *
     * @param array<string, mixed> $params
     */
    private function attemptCouponSave(array $params): void
    {
        $request = Craft::$app->getRequest();
        $request->setBodyParams($params);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Craft::$app->set('response', new Response());

        $controller = new CouponsController('coupons', Plugin::getInstance());
        $controller->enableCsrfValidation = false;

        try {
            $controller->actionSave();
        } catch (\Throwable) {
            // The block/failure path re-renders the CP edit template; ignore.
        }
    }
}

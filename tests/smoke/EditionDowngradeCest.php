<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\controllers\FormsController;
use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use SmokeTester;

/**
 * Pro -> Solo downgrade behaviour: a form built with a Pro feature keeps working
 * after the license drops to Solo (no data loss), the edition gate blocks *new*
 * Pro escalations without blocking preservation of what's already there, and the
 * CP downgrade banner enumerates the in-use Pro features.
 *
 * The suite pins Pro by default; each test flips the edition explicitly and
 * {@see _after} restores it.
 *
 * @author Fabian Haefliger
 * @since 2.17.0
 */
class EditionDowngradeCest extends BaseSmokeCest
{
    // =========================================================================
    // PROPERTIES
    // =========================================================================

    private ?string $originalEdition = null;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _after(SmokeTester $I): void
    {
        if ($this->originalEdition !== null) {
            Plugin::getInstance()->edition = $this->originalEdition;
            $this->originalEdition = null;
        }
    }

    /**
     * A Pro field authored on Pro is preserved verbatim after a downgrade to
     * Solo — the field row is edition-blind at storage, so it still loads.
     */
    public function testProFieldSurvivesDowngrade(SmokeTester $I): void
    {
        $this->setEdition(Editions::PRO);
        $form = $this->createForm('Downgrade', 'dgKeep' . uniqid());
        $ratingId = $this->createField((int) $form->id, 'rating', 'score', 'Score', false, ['max' => 5]);

        $this->setEdition(Editions::SOLO);

        $fields = \anvildev\simpleform\helpers\FieldQueryHelper::fieldsForForm((int) $form->id, $form->siteId);
        $types = array_map(static fn(array $row): string => (string) $row['type'], $fields);

        $I->assertContains('rating', $types, 'the Pro rating field survives the downgrade');
        $I->assertNotNull(
            Plugin::getInstance()->getFieldTypeRegistry()->getFieldType('rating', ['max' => 5]),
            'the Pro field type still resolves and renders on Solo',
        );

        // No-new-escalation: the already-present rating is not re-blocked when it
        // is passed through as existing; adding a *second* rating is blocked.
        $I->assertSame([], Editions::blockedNewProFields(['rating'], ['rating'], Editions::SOLO), 'preserving the existing Pro field is allowed');
        $I->assertSame(['rating'], Editions::blockedNewProFields(['rating', 'rating'], ['rating'], Editions::SOLO), 'adding another Pro field of the same type is blocked');
    }

    /**
     * On Solo, a Pro setting change is blocked only when it *escalates* (enables /
     * makes a still-on feature more destructive); re-saving the stored value
     * (preservation) is always allowed.
     */
    public function testBlocksProSettingEscalationButNotPreservation(SmokeTester $I): void
    {
        $this->setEdition(Editions::SOLO);

        // retainSubmissionsDays is a Pro "on switch": 0 = off, > 0 = on.
        $I->assertTrue(
            Editions::blocksProSettingChange('retainSubmissionsDays', 0, 30),
            'enabling a Pro retention window on Solo is blocked',
        );
        $I->assertTrue(
            Editions::blocksProSettingChange('retainSubmissionsDays', 90, 30),
            'shrinking a still-on window to delete more aggressively is blocked',
        );
        $I->assertFalse(
            Editions::blocksProSettingChange('retainSubmissionsDays', 30, 30),
            'preserving the stored value is allowed',
        );
        $I->assertFalse(
            Editions::blocksProSettingChange('retainSubmissionsDays', 30, 0),
            'turning the Pro feature off is allowed',
        );
        $I->assertFalse(
            Editions::blocksProSettingChange('retainSubmissionsDays', 0, 30, Editions::PRO),
            'Pro is never blocked',
        );
    }

    /**
     * The downgrade banner data ({@see FormsController::proFeaturesInUse()}, private)
     * lists the in-use Pro field type after the flip to Solo, and is empty on Pro.
     */
    public function testDowngradeBannerListsInUseProFeatures(SmokeTester $I): void
    {
        $this->setEdition(Editions::PRO);
        $form = $this->createForm('Downgrade', 'dgBanner' . uniqid());
        $this->createField((int) $form->id, 'rating', 'score', 'Score', false, ['max' => 5]);
        $site = Craft::$app->getSites()->getPrimarySite();

        $form = $this->reloadForm($form);

        // On Pro the banner is silent — nothing is "in use" against the gate.
        $I->assertSame([], $this->proFeaturesInUse($form, $site), 'the banner is empty on Pro');

        $this->setEdition(Editions::SOLO);
        $features = $this->proFeaturesInUse($this->reloadForm($form), $site);

        $I->assertNotEmpty($features, 'the downgrade banner reports in-use Pro features on Solo');
        $I->assertStringContainsString('rating', implode(' ', $features), 'the in-use Pro rating field is named');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function setEdition(string $edition): void
    {
        $plugin = Plugin::getInstance();
        $this->originalEdition ??= $plugin->edition;
        $plugin->edition = $edition;
    }

    /**
     * Invoke the private banner-data method the form-edit screen reads.
     *
     * @return list<string>
     */
    private function proFeaturesInUse(Form $form, \craft\models\Site $site): array
    {
        $method = new \ReflectionMethod(FormsController::class, 'proFeaturesInUse');
        $method->setAccessible(true);
        $controller = new FormsController('forms', Plugin::getInstance());

        return $method->invoke($controller, $form, $site);
    }
}

<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\events\PartialCaptureEvent;
use fabianhaef\simpleform\Plugin;
use yii\base\Event;

/**
 * Privacy + lifecycle governance for passive partial capture (#244): the consent
 * gate, the capture event, the conservative retention window, and removal of a
 * form's partials when the form is deleted (GDPR / subject deletion).
 *
 * @group requires-craft
 */
class PartialGovernanceTest extends SimpleFormTestCase
{
    private int $siteId;

    private function captureForm(string $handle): Form
    {
        $form = $this->createForm('Lead', $handle, 'Lead');
        $form->capturePartials = true;
        Craft::$app->getElements()->saveElement($form);
        return $form;
    }

    private function draftRow(string $tokenHash): ?array
    {
        $row = (new Query())->from('{{%simpleform_form_drafts}}')->where(['tokenHash' => $tokenHash])->one();
        return is_array($row) ? $row : null;
    }

    public function testConsentGateBlocksCaptureUntilGranted(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->captureForm('partialConsent');
        $nameId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        $consentId = $this->createField((int) $form->id, 'consent', 'agree', 'I agree', false, ['consentText' => 'I agree']);
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        $drafts = Plugin::getInstance()->getDrafts();

        // Consent absent / not ticked → capture blocked, nothing stored.
        $blocked = $drafts->capturePartial($form, ['field_' . $nameId => 'Ada'], $this->siteId);
        $this->assertNull($blocked, 'capture blocked without consent');
        $this->assertSame([], $drafts->listPassive((int) $form->id, $this->siteId));

        // Consent granted ("1") → capture proceeds.
        $token = $drafts->capturePartial($form, ['field_' . $nameId => 'Ada', 'field_' . $consentId => '1'], $this->siteId);
        $this->assertNotNull($token);
        $this->assertCount(1, $drafts->listPassive((int) $form->id, $this->siteId));
    }

    public function testCaptureFiresEventWithContext(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->captureForm('partialEvent');
        $nameId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        $captured = null;
        $handler = static function (PartialCaptureEvent $e) use (&$captured): void {
            $captured = $e;
        };
        Event::on(Plugin::class, Plugin::EVENT_PARTIAL_CAPTURED, $handler);

        try {
            $token = Plugin::getInstance()->getDrafts()->capturePartial($form, ['field_' . $nameId => 'Ada'], $this->siteId);
        } finally {
            Event::off(Plugin::class, Plugin::EVENT_PARTIAL_CAPTURED, $handler);
        }

        $this->assertInstanceOf(PartialCaptureEvent::class, $captured);
        $this->assertSame((int) $form->id, (int) $captured->form->id);
        $this->assertSame('Ada', $captured->values['field_' . $nameId]);
        $this->assertSame($token, $captured->token);
    }

    public function testPassivePartialUsesConservativeRetentionWindow(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        Plugin::getInstance()->getSettings()->partialRetentionDays = 3;
        Plugin::getInstance()->getSettings()->draftRetentionDays = 30;

        $form = $this->captureForm('partialRetention');
        $nameId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        $token = Plugin::getInstance()->getDrafts()->capturePartial($form, ['field_' . $nameId => 'Ada'], $this->siteId);
        $row = $this->draftRow(hash('sha256', (string) $token));
        $this->assertNotNull($row);

        $expires = new \DateTime((string) $row['dateExpires']);
        $now = new \DateTime();
        $daysOut = ($expires->getTimestamp() - $now->getTimestamp()) / 86400;
        // ~3 days out (the conservative partial window), well under the 30-day draft window.
        $this->assertGreaterThan(2, $daysOut);
        $this->assertLessThan(5, $daysOut, 'partial uses the conservative window, not the draft window');
    }

    public function testDeletingTheFormRemovesItsPartials(): void
    {
        $this->requireCraft();
        $this->siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $form = $this->captureForm('partialGdpr');
        $nameId = $this->createField((int) $form->id, 'text', 'name', 'Name');
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        $token = Plugin::getInstance()->getDrafts()->capturePartial($form, ['field_' . $nameId => 'Ada'], $this->siteId);
        $this->assertNotNull($this->draftRow(hash('sha256', (string) $token)));

        // Hard-deleting the form cascades to its drafts (subject/GDPR removal,
        // exactly like a submission row tied to the form).
        Craft::$app->getElements()->deleteElement($form, true);

        $this->assertNull($this->draftRow(hash('sha256', (string) $token)), 'partials removed with the form');
    }
}

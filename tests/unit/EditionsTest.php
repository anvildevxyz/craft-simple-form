<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\Editions;
use PHPUnit\Framework\TestCase;

/**
 * The capability matrix is pure logic — every predicate accepts an explicit
 * edition so it can be exercised without a Craft instance.
 */
class EditionsTest extends TestCase
{
    public function testProGetsEveryCapability(): void
    {
        foreach ($this->allCapabilities() as $cap) {
            $this->assertTrue(Editions::can($cap, Editions::STANDARD), "Pro should allow $cap");
        }
    }

    public function testSoloGetsNoGatedCapability(): void
    {
        foreach ($this->allCapabilities() as $cap) {
            $this->assertFalse(Editions::can($cap, Editions::SOLO), "Solo should not allow $cap");
        }
    }

    public function testProAllowsEveryField(): void
    {
        foreach (Editions::STANDARD_FIELDS as $handle) {
            $this->assertTrue(Editions::fieldTypeAllowed($handle, Editions::STANDARD));
        }
    }

    public function testSoloBlocksStandardFieldsButAllowsCoreFields(): void
    {
        foreach (Editions::STANDARD_FIELDS as $handle) {
            $this->assertFalse(Editions::fieldTypeAllowed($handle, Editions::SOLO), "Solo should block $handle");
        }

        foreach (['text', 'email', 'textarea', 'select', 'checkbox', 'date', 'file', 'name', 'address', 'consent'] as $handle) {
            $this->assertTrue(Editions::fieldTypeAllowed($handle, Editions::SOLO), "Solo should allow core field $handle");
        }
    }

    public function testSoloIntegrationsAreLimitedToTheAllowList(): void
    {
        foreach (Editions::SOLO_INTEGRATIONS as $handle) {
            $this->assertTrue(Editions::integrationAllowed($handle, Editions::SOLO));
            $this->assertTrue(Editions::integrationAllowed($handle, Editions::STANDARD));
        }

        foreach (['slack', 'discord', 'mailchimp', 'activecampaign', 'hubspot', 'pipedrive', 'google-sheets'] as $handle) {
            $this->assertFalse(Editions::integrationAllowed($handle, Editions::SOLO), "Solo should block $handle");
            $this->assertTrue(Editions::integrationAllowed($handle, Editions::STANDARD));
        }
    }

    public function testBlockedNewStandardFieldsAppliesNoEscalationRule(): void
    {
        // Pro: nothing is ever blocked.
        $this->assertSame([], Editions::blockedNewStandardFields(['payment', 'signature'], [], Editions::STANDARD));

        // Solo, fresh form: every Pro field is a blocked escalation; core fields pass.
        $this->assertSame(
            ['payment', 'rating'],
            Editions::blockedNewStandardFields(['text', 'payment', 'email', 'rating'], [], Editions::SOLO),
        );

        // Solo, downgraded form already containing a Pro field: keeping it is allowed,
        // adding a *new* Pro field is blocked.
        $this->assertSame([], Editions::blockedNewStandardFields(['text', 'payment'], ['payment'], Editions::SOLO));
        $this->assertSame(
            ['signature'],
            Editions::blockedNewStandardFields(['payment', 'signature'], ['payment'], Editions::SOLO),
        );

        // Count-aware: keeping the one existing Pro field is allowed, but adding a
        // *second* field of that already-present type is still an escalation.
        $this->assertSame(['rating'], Editions::blockedNewStandardFields(['rating', 'rating'], ['rating'], Editions::SOLO));

        // Duplicate blocked handles collapse to one.
        $this->assertSame(['rating'], Editions::blockedNewStandardFields(['rating', 'rating'], [], Editions::SOLO));
    }

    public function testBlocksProSettingChangeAllowsOnlyOffOrUnchanged(): void
    {
        // Pro: never blocked.
        $this->assertFalse(Editions::blocksStandardSettingChange('enableAkismet', false, true, Editions::STANDARD));

        // A non-gated setting is never blocked.
        $this->assertFalse(Editions::blocksStandardSettingChange('enableHoneypot', false, true, Editions::SOLO));

        // Solo: enabling a Standard feature is blocked...
        $this->assertTrue(Editions::blocksStandardSettingChange('enableAkismet', false, true, Editions::SOLO));
        $this->assertTrue(Editions::blocksStandardSettingChange('enableDenylists', false, true, Editions::SOLO));
        $this->assertTrue(Editions::blocksStandardSettingChange('retainSubmissionsDays', 0, 30, Editions::SOLO));
        $this->assertTrue(Editions::blocksStandardSettingChange('retainAuditLogDays', 0, 30, Editions::SOLO));
        // Submission approval workflow is Solo-free (#283 split): never gated.
        $this->assertFalse(Editions::blocksStandardSettingChange('enableWorkflow', false, true, Editions::SOLO));

        // ...as is changing a still-on value (e.g. shrinking — more destructive —
        // or even growing the retention window: it's still reconfiguring Pro).
        $this->assertTrue(Editions::blocksStandardSettingChange('retainSubmissionsDays', 30, 1, Editions::SOLO));
        $this->assertTrue(Editions::blocksStandardSettingChange('retainSubmissionsDays', 30, 60, Editions::SOLO));

        // But turning it off, or leaving it exactly as-is, is always allowed (so a
        // downgraded site can still stop a running Standard feature).
        $this->assertFalse(Editions::blocksStandardSettingChange('enableAkismet', true, false, Editions::SOLO));
        $this->assertFalse(Editions::blocksStandardSettingChange('enableAkismet', true, true, Editions::SOLO));
        $this->assertFalse(Editions::blocksStandardSettingChange('retainSubmissionsDays', 30, 0, Editions::SOLO));
        $this->assertFalse(Editions::blocksStandardSettingChange('retainSubmissionsDays', 30, 30, Editions::SOLO));

        // Spam verdict modes: escalating to the destructive 'block' is blocked,
        // but de-escalating to the safe 'flag' (so a downgraded site can stop
        // silently dropping legitimate submissions) is always allowed.
        $this->assertTrue(Editions::blocksStandardSettingChange('akismetMode', 'flag', 'block', Editions::SOLO));
        $this->assertTrue(Editions::blocksStandardSettingChange('denylistMode', 'flag', 'block', Editions::SOLO));
        $this->assertFalse(Editions::blocksStandardSettingChange('akismetMode', 'block', 'flag', Editions::SOLO));
        $this->assertFalse(Editions::blocksStandardSettingChange('akismetMode', 'block', 'block', Editions::SOLO));
        $this->assertFalse(Editions::blocksStandardSettingChange('akismetMode', 'flag', 'block', Editions::STANDARD));
    }

    public function testDefaultOpenForUnknownEdition(): void
    {
        // Anything that is not explicitly Solo behaves as Pro, so an unset or
        // off-license edition never accidentally restricts authoring.
        $this->assertTrue(Editions::isStandard('standard'));
        $this->assertTrue(Editions::isStandard(Editions::STANDARD));
        $this->assertFalse(Editions::isStandard(Editions::SOLO));
        $this->assertTrue(Editions::can(Editions::CAP_PDF, 'standard'));
    }

    public function testDetectsConditionalLogicAndMultiPage(): void
    {
        $plain = [['type' => 'text', 'config' => []]];
        $conditional = [['type' => 'text', 'config' => ['conditional' => ['enabled' => true, 'rules' => [['field' => 'a', 'value' => 'x']]]]]];
        $conditionalRequired = [['type' => 'text', 'config' => ['conditional' => ['required' => ['enabled' => true, 'rules' => [['field' => 'a']]]]]]];
        $emptyConditional = [['type' => 'text', 'config' => ['conditional' => ['enabled' => true, 'rules' => []]]]];
        // Rules present but the block is disabled: inert at render time per
        // ConditionalEvaluator, so it must not count as Pro usage here either.
        $disabledConditional = [['type' => 'text', 'config' => ['conditional' => ['enabled' => false, 'rules' => [['field' => 'a', 'value' => 'x']]]]]];
        $multiPage = [['type' => 'text', 'config' => ['page' => 1]], ['type' => 'email', 'config' => ['page' => 2]]];

        $this->assertFalse(Editions::usesConditionalLogic($plain));
        $this->assertFalse(Editions::usesConditionalLogic($emptyConditional));
        $this->assertFalse(Editions::usesConditionalLogic($disabledConditional));
        $this->assertTrue(Editions::usesConditionalLogic($conditional));
        $this->assertTrue(Editions::usesConditionalLogic($conditionalRequired));

        $this->assertFalse(Editions::usesMultiPage($plain));
        $this->assertTrue(Editions::usesMultiPage($multiPage));
    }

    public function testBlockedNewFormCapabilitiesAppliesNoEscalationRule(): void
    {
        $conditional = [['type' => 'text', 'config' => ['conditional' => ['enabled' => true, 'rules' => [['field' => 'a']]]]]];
        $plain = [['type' => 'text', 'config' => []]];

        // Pro: never blocked.
        $this->assertSame([], Editions::blockedNewFormCapabilities($conditional, true, $plain, false, Editions::STANDARD));

        // Solo, fresh form newly enabling all three: every one is blocked.
        $this->assertSame(
            [Editions::CAP_CONDITIONAL_LOGIC, Editions::CAP_SAVE_CONTINUE],
            Editions::blockedNewFormCapabilities($conditional, true, $plain, false, Editions::SOLO),
        );

        // Solo, downgraded form that already had conditional logic + save-resume:
        // keeping them is allowed.
        $this->assertSame([], Editions::blockedNewFormCapabilities($conditional, true, $conditional, true, Editions::SOLO));
    }

    public function testLogicJumpsAreSoloFree(): void
    {
        // Logic jumps are Solo-free (#283 split): a field carrying config.jumps
        // is never a Pro escalation, on either edition.
        $jump = [['type' => 'select', 'config' => ['jumps' => [['target' => 'thanks', 'operator' => 'eq', 'value' => 'a']]]]];

        $this->assertSame([], Editions::blockedNewFormCapabilities($jump, false, [], false, Editions::SOLO));
        $this->assertSame([], Editions::blockedNewFormCapabilities($jump, false, [], false, Editions::STANDARD));
    }

    public function testBlockedNewFormModesAppliesNoEscalationRule(): void
    {
        $allOn = [Editions::CAP_CONVERSATIONAL => true, Editions::CAP_QUIZ => true, Editions::CAP_PARTIAL_CAPTURE => true];
        $allOff = [Editions::CAP_CONVERSATIONAL => false, Editions::CAP_QUIZ => false, Editions::CAP_PARTIAL_CAPTURE => false];

        // Pro: never blocked.
        $this->assertSame([], Editions::blockedNewFormModes($allOn, $allOff, Editions::STANDARD));

        // Solo, switching every mode on from off: each is a blocked escalation.
        $this->assertSame(
            [Editions::CAP_CONVERSATIONAL, Editions::CAP_QUIZ, Editions::CAP_PARTIAL_CAPTURE],
            Editions::blockedNewFormModes($allOn, $allOff, Editions::SOLO),
        );

        // Solo, modes already on: preserved (posted == stored).
        $this->assertSame([], Editions::blockedNewFormModes($allOn, $allOn, Editions::SOLO));

        // Solo, only quiz is newly switched on; the others were already on.
        $this->assertSame(
            [Editions::CAP_QUIZ],
            Editions::blockedNewFormModes(
                $allOn,
                [Editions::CAP_CONVERSATIONAL => true, Editions::CAP_QUIZ => false, Editions::CAP_PARTIAL_CAPTURE => true],
                Editions::SOLO,
            ),
        );
    }

    /**
     * @return list<string>
     */
    private function allCapabilities(): array
    {
        return [
            Editions::CAP_CONDITIONAL_LOGIC,
            Editions::CAP_MULTI_PAGE,
            Editions::CAP_SAVE_CONTINUE,
            Editions::CAP_CONVERSATIONAL,
            Editions::CAP_QUIZ,
            Editions::CAP_PARTIAL_CAPTURE,
            Editions::CAP_PDF,
            Editions::CAP_GOVERNANCE,
            Editions::CAP_DEV_TOOLS,
        ];
    }
}

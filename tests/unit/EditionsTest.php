<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\Editions;
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
            $this->assertTrue(Editions::can($cap, Editions::PRO), "Pro should allow $cap");
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
        foreach (Editions::PRO_FIELDS as $handle) {
            $this->assertTrue(Editions::fieldTypeAllowed($handle, Editions::PRO));
        }
    }

    public function testSoloBlocksProFieldsButAllowsCoreFields(): void
    {
        foreach (Editions::PRO_FIELDS as $handle) {
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
            $this->assertTrue(Editions::integrationAllowed($handle, Editions::PRO));
        }

        foreach (['slack', 'discord', 'mailchimp', 'activecampaign', 'hubspot', 'pipedrive', 'google-sheets'] as $handle) {
            $this->assertFalse(Editions::integrationAllowed($handle, Editions::SOLO), "Solo should block $handle");
            $this->assertTrue(Editions::integrationAllowed($handle, Editions::PRO));
        }
    }

    public function testBlockedNewProFieldsAppliesNoEscalationRule(): void
    {
        // Pro: nothing is ever blocked.
        $this->assertSame([], Editions::blockedNewProFields(['payment', 'signature'], [], Editions::PRO));

        // Solo, fresh form: every Pro field is a blocked escalation; core fields pass.
        $this->assertSame(
            ['payment', 'rating'],
            Editions::blockedNewProFields(['text', 'payment', 'email', 'rating'], [], Editions::SOLO),
        );

        // Solo, downgraded form already containing a Pro field: keeping it is allowed,
        // adding a *new* Pro field is blocked.
        $this->assertSame([], Editions::blockedNewProFields(['text', 'payment'], ['payment'], Editions::SOLO));
        $this->assertSame(
            ['signature'],
            Editions::blockedNewProFields(['payment', 'signature'], ['payment'], Editions::SOLO),
        );

        // Duplicate blocked handles collapse to one.
        $this->assertSame(['rating'], Editions::blockedNewProFields(['rating', 'rating'], [], Editions::SOLO));
    }

    public function testDefaultOpenForUnknownEdition(): void
    {
        // Anything that is not explicitly Solo behaves as Pro, so an unset or
        // off-license edition never accidentally restricts authoring.
        $this->assertTrue(Editions::isPro('standard'));
        $this->assertTrue(Editions::isPro(Editions::PRO));
        $this->assertFalse(Editions::isPro(Editions::SOLO));
        $this->assertTrue(Editions::can(Editions::CAP_PAYMENTS, 'standard'));
    }

    /**
     * @return list<string>
     */
    private function allCapabilities(): array
    {
        return [
            Editions::CAP_PRO_FIELDS,
            Editions::CAP_CONDITIONAL_LOGIC,
            Editions::CAP_MULTI_PAGE,
            Editions::CAP_MULTI_SITE,
            Editions::CAP_INTEGRATIONS,
            Editions::CAP_PAYMENTS,
            Editions::CAP_SPAM_ADVANCED,
            Editions::CAP_PDF,
            Editions::CAP_GOVERNANCE,
            Editions::CAP_DEV_TOOLS,
        ];
    }
}

<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\Plugin;
use Craft;
use SmokeTester;

/**
 * Passive partial capture smoke tests (#242): the public form exposes the
 * capture endpoint + token input only when the form opted in.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class PartialCaptureSmokeCest extends BaseSmokeCest
{
    public function testCaptureWiringRendersOnlyWhenEnabled(SmokeTester $I): void
    {
        $on = $this->renderWithCapture('partialOn' . uniqid(), true);
        $I->assertStringContainsString('data-sf-capture=', $on);
        $I->assertStringContainsString('name="partialToken"', $on);
        $I->assertStringContainsString('data-sf-partial-token', $on);

        $off = $this->renderWithCapture('partialOff' . uniqid(), false);
        $I->assertStringNotContainsString('data-sf-capture=', $off);
        $I->assertStringNotContainsString('name="partialToken"', $off);
    }

    private function renderWithCapture(string $handle, bool $capture): string
    {
        $form = $this->createForm('Lead', $handle);
        $form->capturePartials = $capture;
        Craft::$app->getElements()->saveElement($form);
        $this->createField((int) $form->id, 'text', 'name', 'Name');
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        return $this->renderForm($handle);
    }
}

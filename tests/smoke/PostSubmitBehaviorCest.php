<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\Plugin;
use SmokeTester;

/**
 * Post-Submit Behavior Smoke Tests (#133, functional).
 *
 * Exercises the per-form success message override (with placeholder
 * interpolation), the global fallback, and the URL/entry redirect actions
 * through the shared submit path + the real
 * {@see \fabianhaef\simpleform\services\SubmissionService::resolvePostSubmit()}
 * — the exact resolution the SubmitController and GraphQL mutation both use.
 * Forms and fields are seeded through the data layer (see {@see BaseSmokeCest}).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class PostSubmitBehaviorCest extends BaseSmokeCest
{
    // =========================================================================
    // PRIVATE PROPERTIES
    // =========================================================================

    private int $formId;

    private string $formHandle;

    private int $fieldId;

    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function _before(SmokeTester $I): void
    {
        $form = $this->createForm('Post-Submit Test Form', 'postSubmit' . uniqid(), 'admin@test.com');
        $this->formId = (int)$form->id;
        $this->formHandle = $form->handle;
        $this->fieldId = $this->createField($this->formId, 'text', 'firstName', 'First Name');
    }

    public function testPerFormMessageInterpolatesSubmittedValue(SmokeTester $I): void
    {
        $form = $this->form();
        $form->submitMessage = 'Thanks {firstName}!';
        Craft::$app->getElements()->saveElement($form);

        $envelope = $this->submitAndResolve($I, 'Ada');

        $I->assertSame('Thanks Ada!', $envelope['message']);
        $I->assertNull($envelope['redirectUrl'], 'message action has no redirect');
    }

    public function testBlankMessageFallsBackToGlobalDefault(SmokeTester $I): void
    {
        $global = Plugin::getInstance()->getSettings()->submitMessage;

        $envelope = $this->submitAndResolve($I, 'Ada');

        $I->assertSame($global, $envelope['message']);
    }

    public function testUrlActionReturnsEncodedRedirect(SmokeTester $I): void
    {
        $form = $this->form();
        $form->postSubmitAction = 'url';
        $form->redirectUrl = '/thanks?n={firstName}';
        Craft::$app->getElements()->saveElement($form);

        $envelope = $this->submitAndResolve($I, 'Ada Lovelace');

        $I->assertSame('/thanks?n=Ada%20Lovelace', $envelope['redirectUrl']);
    }

    public function testEntryActionWithMissingEntryFallsBackToInlineMessage(SmokeTester $I): void
    {
        $form = $this->form();
        $form->postSubmitAction = 'entry';
        $form->redirectEntryId = 999999; // no such entry → null redirect
        Craft::$app->getElements()->saveElement($form);

        $envelope = $this->submitAndResolve($I, 'Ada');

        $I->assertNull($envelope['redirectUrl'], 'missing entry yields inline message');
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    /**
     * The form element reloaded fresh from the current site.
     */
    private function form(): Form
    {
        return Form::find()->id($this->formId)->siteId(Craft::$app->getSites()->getPrimarySite()->id)->one();
    }

    /**
     * Submit the seeded form with the given first-name value, then resolve the
     * post-submit envelope exactly as the SubmitController does.
     *
     * @return array{message: string, redirectUrl: string|null}
     */
    private function submitAndResolve(SmokeTester $I, string $firstName): array
    {
        $result = $this->submitRequest($this->formHandle, ['field_' . $this->fieldId => $firstName]);
        $I->assertInstanceOf(Submission::class, $result['submission']);

        return $this->service()->resolvePostSubmit($this->form(), $result['submission'], $result['data'] ?? []);
    }
}

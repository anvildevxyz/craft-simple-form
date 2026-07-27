<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Editions;
use anvildev\simpleform\elements\Form;
use anvildev\simpleform\models\SubmitMessageModel;
use anvildev\simpleform\Plugin;
use Craft;
use SmokeTester;

/**
 * Pro back-office pause/resume on downgrade, via conditional submit messages
 * (#265). {@see \anvildev\simpleform\services\SubmissionService::resolvePostSubmit()}
 * evaluates a form's conditional "thank you" rules only when the edition may use
 * conditional logic; a downgraded Solo keeps its stored rows but skips them,
 * falling straight back to the plain/global message with no error or data loss.
 *
 * The rule is authored on Pro (creating a rule is Pro-gated in
 * {@see \anvildev\simpleform\services\SubmitMessagesService::save()}); the suite
 * pins Pro by default. {@see _after} restores the edition.
 *
 * @author Anvil Dev
 * @since 2.17.0
 */
class EditionPauseResumeCest extends BaseSmokeCest
{
    // =========================================================================
    // CONST PROPERTIES
    // =========================================================================

    private const RULE_MESSAGE = 'Our sales team will be in touch shortly.';
    private const GLOBAL_MESSAGE = 'Thank you! Your submission has been received.';

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
     * On Pro the conditional rule fires: a matching submission resolves to the
     * rule's message rather than the form/global default.
     */
    public function testProResolvesConditionalRuleMessage(SmokeTester $I): void
    {
        $this->setEdition(Editions::PRO);
        [$form, $topicId] = $this->seedFormWithConditionalMessage();

        $resolved = $this->resolve($form, $topicId, 'sales');

        $I->assertSame(self::RULE_MESSAGE, $resolved['message'], 'the matching Pro rule wins on Pro');
    }

    /**
     * On Solo the very same form + submission falls back to the plain global
     * message — the stored rule is skipped, with no error.
     */
    public function testSoloFallsBackToGlobalMessageWithoutError(SmokeTester $I): void
    {
        // Author the rule on Pro (creating a rule is Pro-gated)...
        $this->setEdition(Editions::PRO);
        [$form, $topicId] = $this->seedFormWithConditionalMessage();

        // ...then downgrade and confirm the rule is paused, not applied.
        $this->setEdition(Editions::SOLO);
        $resolved = $this->resolve($form, $topicId, 'sales');

        $I->assertSame(self::GLOBAL_MESSAGE, $resolved['message'], 'Solo skips the conditional rule and uses the default');
        $I->assertNotSame(self::RULE_MESSAGE, $resolved['message'], 'the paused rule does not fire on Solo');
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
     * A message-action form with a `topic` text field and one conditional submit
     * message that fires when `topic == sales`.
     *
     * @return array{0: Form, 1: int}
     */
    private function seedFormWithConditionalMessage(): array
    {
        $form = $this->createForm('Pause', 'pauseCond' . uniqid());
        // postSubmitAction defaults to 'message'; leave submitMessage null so the
        // fallback is the plain global default.
        $topicId = $this->createField((int) $form->id, 'text', 'topic', 'Topic');

        $model = new SubmitMessageModel();
        $model->formId = (int) $form->id;
        $model->conditional = [
            'enabled' => true,
            'match' => 'all',
            'action' => 'show',
            'rules' => [
                ['field' => 'topic', 'operator' => 'eq', 'value' => 'sales'],
            ],
        ];
        $model->messages = [Craft::$app->getSites()->getPrimarySite()->id => self::RULE_MESSAGE];

        $saved = Plugin::getInstance()->getSubmitMessages()->save($model);
        if (!$saved) {
            throw new \RuntimeException('Failed to seed conditional submit message: ' . implode(', ', $model->getFirstErrors()));
        }

        return [$this->reloadForm($form), $topicId];
    }

    /**
     * Submit `topic = $topic` and resolve the post-submit message the way the
     * submit controller does.
     *
     * @return array{message: string, redirectUrl: ?string}
     */
    private function resolve(Form $form, int $topicId, string $topic): array
    {
        $result = $this->submitRequest($form->handle, ['field_' . $topicId => $topic]);
        $submission = $result['submission'];
        if ($submission === null) {
            throw new \RuntimeException('Submission failed during resolve()');
        }

        return $this->service()->resolvePostSubmit($form, $submission, $result['data'] ?? []);
    }
}

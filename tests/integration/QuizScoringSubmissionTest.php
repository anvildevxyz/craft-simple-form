<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Form;
use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\services\SubmissionService;
use Craft;
use craft\db\Query;

/**
 * End-to-end quiz scoring (#241): the answer key on choice fields drives a score
 * computed once at submit and persisted on the Submission, with grade bands, and
 * existing submissions are never retroactively rescored when the key changes.
 *
 * @group requires-craft
 */
class QuizScoringSubmissionTest extends SimpleFormTestCase
{
    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }

    private function quizForm(string $handle, ?string $bands = null): Form
    {
        $form = $this->createForm('Quiz', $handle, 'Quiz');
        $form->quizMode = true;
        $form->quizGradeBands = $bands;
        Craft::$app->getElements()->saveElement($form);
        return $form;
    }

    /**
     * @param array<int, array<string, mixed>> $options
     */
    private function choiceField(int $formId, string $type, string $handle, array $options): int
    {
        return $this->createField($formId, $type, $handle, ucfirst($handle), false, ['options' => $options]);
    }

    public function testScoreIsComputedAndStoredAtSubmit(): void
    {
        $this->requireCraft();

        $form = $this->quizForm('quizScoreForm', "90 Excellent\n50 Pass\n0 Fail");
        $q1 = $this->choiceField((int) $form->id, 'radio', 'capital', [
            ['value' => 'paris', 'label' => 'Paris', 'correct' => true, 'points' => 1],
            ['value' => 'lyon', 'label' => 'Lyon'],
        ]);
        $q2 = $this->choiceField((int) $form->id, 'checkbox', 'primes', [
            ['value' => '2', 'label' => 'Two', 'correct' => true, 'points' => 1],
            ['value' => '3', 'label' => 'Three', 'correct' => true, 'points' => 1],
            ['value' => '4', 'label' => 'Four'],
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'quizScoreForm',
            'field_' . $q1 => 'paris',          // correct: +1
            'field_' . $q2 => ['2', '4'],       // one right (+1), one distractor (+0)
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertNull($result['errors']);
        $submission = $result['submission'];
        $this->assertInstanceOf(Submission::class, $submission);

        // 2 of a possible 3 → 67%, which lands in the "Pass" band.
        $this->assertSame(2, $submission->quizScore);
        $this->assertSame(3, $submission->quizMaxScore);
        $this->assertSame(67, $submission->quizPercentage);
        $this->assertSame('Pass', $submission->quizGrade);

        // Persisted to the row, not just the in-memory element.
        $row = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $submission->id])
            ->one();
        $this->assertSame(2, (int) $row['quizScore']);
        $this->assertSame(67, (int) $row['quizPercentage']);
        $this->assertSame('Pass', $row['quizGrade']);
    }

    public function testNonQuizFormLeavesScoreNull(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Plain', 'plainNoQuizForm', 'Plain');
        $fieldId = $this->choiceField((int) $form->id, 'radio', 'pick', [
            ['value' => 'a', 'label' => 'A', 'correct' => true, 'points' => 1],
            ['value' => 'b', 'label' => 'B'],
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => 'plainNoQuizForm', 'field_' . $fieldId => 'a']);

        $result = $this->submissionService()->createFromRequest($form, $request);
        $this->assertNull($result['errors']);
        $submission = $result['submission'];
        $this->assertInstanceOf(Submission::class, $submission);

        $this->assertNull($submission->quizScore);
        $this->assertNull($submission->quizPercentage);
        $this->assertNull($submission->quizGrade);
    }

    public function testAnswerKeyChangeDoesNotRescoreExistingSubmissions(): void
    {
        $this->requireCraft();

        $form = $this->quizForm('quizRescoreForm');
        $fieldId = $this->choiceField((int) $form->id, 'radio', 'answer', [
            ['value' => 'a', 'label' => 'A', 'correct' => true, 'points' => 1],
            ['value' => 'b', 'label' => 'B'],
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams(['formHandle' => 'quizRescoreForm', 'field_' . $fieldId => 'a']);
        $first = $this->submissionService()->createFromRequest($form, $request)['submission'];
        $this->assertInstanceOf(Submission::class, $first);
        $this->assertSame(1, $first->quizScore);
        $this->assertSame(100, $first->quizPercentage);

        // Flip the answer key: 'a' is now wrong, 'b' is correct. Pass the config
        // as an array (Yii encodes it for the json column) — the same shape
        // createField() writes, so the reader decodes it cleanly.
        Craft::$app->getDb()->createCommand()->update(
            '{{%simpleform_fields}}',
            ['config' => ['options' => [
                ['value' => 'a', 'label' => 'A'],
                ['value' => 'b', 'label' => 'B', 'correct' => true, 'points' => 1],
            ]]],
            ['id' => $fieldId],
        )->execute();
        Plugin::getInstance()->getFormStructure()->invalidate((int) $form->id);

        // The existing submission's stored score is untouched by the key change.
        $row = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $first->id])
            ->one();
        $this->assertSame(1, (int) $row['quizScore']);
        $this->assertSame(100, (int) $row['quizPercentage']);

        // But a new submission with the same answer is scored against the new key.
        $request2 = Craft::$app->getRequest();
        $request2->setBodyParams(['formHandle' => 'quizRescoreForm', 'field_' . $fieldId => 'a']);
        $second = $this->submissionService()->createFromRequest($form, $request2)['submission'];
        $this->assertInstanceOf(Submission::class, $second);
        $this->assertSame(0, $second->quizScore, 'new submission reflects the updated key');
        $this->assertSame(0, $second->quizPercentage);
    }
}

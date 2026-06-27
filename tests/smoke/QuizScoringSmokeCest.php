<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\helpers\SubmissionCsv;
use SmokeTester;

/**
 * Quiz scoring smoke tests (#241): the answer key drives a stored score and
 * grade band, the result surfaces in the success message and the CSV export.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class QuizScoringSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testScoreAndGradeAreStored(SmokeTester $I): void
    {
        $form = $this->quizForm('quizSmoke' . uniqid(), "80 Pass\n0 Fail");
        $fieldId = $this->answerField((int) $form->id);

        $pass = $this->submitRequest($form->handle, ['field_' . $fieldId => 'right']);
        $I->assertNull($pass['errors']);
        $I->assertSame(2, $pass['submission']->quizScore);
        $I->assertSame(2, $pass['submission']->quizMaxScore);
        $I->assertSame(100, $pass['submission']->quizPercentage);
        $I->assertSame('Pass', $pass['submission']->quizGrade);

        $fail = $this->submitRequest($form->handle, ['field_' . $fieldId => 'wrong']);
        $I->assertSame(0, $fail['submission']->quizScore);
        $I->assertSame(0, $fail['submission']->quizPercentage);
        $I->assertSame('Fail', $fail['submission']->quizGrade);
    }

    public function testScorePlaceholdersRenderInSuccessMessage(SmokeTester $I): void
    {
        $form = $this->quizForm('quizMsg' . uniqid());
        $form->submitMessage = 'You scored {quizScore}/{quizMaxScore} ({quizPercentage}).';
        Craft::$app->getElements()->saveElement($form);
        $fieldId = $this->answerField((int) $form->id);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => 'right']);
        $post = $this->service()->resolvePostSubmit($form, $result['submission'], $result['data'] ?? []);

        $I->assertStringContainsString('You scored 2/2 (100%).', $post['message']);
    }

    public function testQuizColumnsAppearInCsvExport(SmokeTester $I): void
    {
        $form = $this->quizForm('quizCsv' . uniqid());
        $fieldId = $this->answerField((int) $form->id);

        $result = $this->submitRequest($form->handle, ['field_' . $fieldId => 'right']);
        $csv = SubmissionCsv::fromSubmissions([$result['submission']]);

        $I->assertStringContainsString('Score', $csv);
        $I->assertStringContainsString('Percentage', $csv);
        // The row carries the computed values.
        $I->assertStringContainsString('100%', $csv);
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function quizForm(string $handle, ?string $bands = null): Form
    {
        $form = $this->createForm('Quiz', $handle);
        $form->quizMode = true;
        $form->quizGradeBands = $bands;
        Craft::$app->getElements()->saveElement($form);

        return $form;
    }

    private function answerField(int $formId): int
    {
        return $this->createField($formId, 'radio', 'answer', 'Answer', false, [
            'options' => [
                ['value' => 'right', 'label' => 'Right', 'correct' => true, 'points' => 2],
                ['value' => 'wrong', 'label' => 'Wrong'],
            ],
        ]);
    }
}

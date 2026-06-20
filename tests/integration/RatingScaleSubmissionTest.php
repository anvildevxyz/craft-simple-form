<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use craft\db\Query;
use fabianhaef\simpleform\elements\Submission;
use fabianhaef\simpleform\elements\SubmissionStatus;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\services\SubmissionService;

/**
 * #128 — end-to-end coverage for the Rating and Opinion Scale field types: the
 * chosen value round-trips as an int through the real submission path, a forged
 * out-of-range value is rejected server-side, and ReportsService aggregates the
 * stored integers into an average + distribution.
 *
 * @group requires-craft
 */
class RatingScaleSubmissionTest extends SimpleFormTestCase
{
    public function testRatingAndOpinionValuesStoreAsIntegers(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Survey', 'scaleSurveyForm', 'Survey');
        $ratingId = $this->createField($form->id, 'rating', 'experience', 'Experience', true, ['max' => 5, 'iconStyle' => 'star']);
        $npsId = $this->createField($form->id, 'opinion', 'recommend', 'Recommend', true, [
            'min' => 0,
            'max' => 10,
            'leftLabel' => 'Not likely',
            'rightLabel' => 'Very likely',
        ]);

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'scaleSurveyForm',
            // Posted as strings, exactly like a real form submit.
            'field_' . $ratingId => '4',
            'field_' . $npsId => '9',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['errors']);
        $this->assertInstanceOf(Submission::class, $result['submission']);

        $row = (new Query())
            ->from('{{%simpleform_submissions}}')
            ->where(['id' => $result['submission']->id])
            ->one();
        $decoded = is_array($row['data']) ? $row['data'] : json_decode((string) $row['data'], true);

        // Stored as ints, not the posted strings — what lets analytics/export
        // treat the column numerically.
        $this->assertSame(4, $decoded['field_' . $ratingId]['value']);
        $this->assertSame(9, $decoded['field_' . $npsId]['value']);
        $this->assertSame('rating', $decoded['field_' . $ratingId]['type']);
        $this->assertSame('opinion', $decoded['field_' . $npsId]['type']);
    }

    public function testOutOfRangeRatingIsRejected(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Forged', 'forgedRatingForm', 'Forged');
        $ratingId = $this->createField($form->id, 'rating', 'stars', 'Stars', true, ['max' => 5]);

        $before = (new Query())->from('{{%simpleform_submissions}}')->count();

        $request = Craft::$app->getRequest();
        $request->setBodyParams([
            'formHandle' => 'forgedRatingForm',
            // 6 is outside the 1–5 range — must be rejected server-side.
            'field_' . $ratingId => '6',
        ]);

        $result = $this->submissionService()->createFromRequest($form, $request);

        $this->assertNull($result['submission']);
        $this->assertNotNull($result['errors']);
        $this->assertArrayHasKey('field_' . $ratingId, $result['errors']);

        $after = (new Query())->from('{{%simpleform_submissions}}')->count();
        $this->assertSame($before, $after, 'No row should persist for a forged out-of-range value');
    }

    public function testScaleBreakdownComputesAverageAndDistribution(): void
    {
        $this->requireCraft();

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $form = $this->createForm('Ratings', 'scaleReportForm', 'Ratings');
        $ratingId = $this->createField($form->id, 'rating', 'score', 'Score', true, ['max' => 5]);
        $key = 'field_' . $ratingId;

        foreach ([5, 5, 3] as $value) {
            $sub = new Submission();
            $sub->formId = (int) $form->id;
            $sub->siteId = $siteId;
            $sub->data = [$key => ['label' => 'Score', 'type' => 'rating', 'value' => $value]];
            $sub->readStatus = SubmissionStatus::NEW;
            Craft::$app->getElements()->saveElement($sub);
        }

        $scales = Plugin::getInstance()->getReports()->scaleBreakdown($siteId, (int) $form->id);

        $this->assertCount(1, $scales);
        $stat = $scales[0];
        $this->assertSame($key, $stat['key']);
        $this->assertSame('rating', $stat['type']);
        $this->assertSame(3, $stat['count']);
        // (5 + 5 + 3) / 3 = 4.333... → rounded to one decimal.
        $this->assertSame(4.3, $stat['average']);
        $this->assertSame([3 => 1, 5 => 2], $stat['distribution']);
    }

    private function submissionService(): SubmissionService
    {
        /** @var SubmissionService $service */
        $service = Plugin::getInstance()->get('submissionService');
        return $service;
    }
}

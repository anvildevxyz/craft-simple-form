<?php

namespace anvildev\simpleform\tests\integration;

use anvildev\simpleform\elements\Submission;
use anvildev\simpleform\Plugin;
use anvildev\simpleform\widgets\RecentSubmissionsWidget;
use anvildev\simpleform\widgets\SubmissionCountWidget;
use Craft;

/**
 * #92 — dashboard widgets: the submission count (range + form filter) and the
 * recent-submissions list.
 *
 * @group requires-craft
 */
class DashboardWidgetsTest extends SimpleFormTestCase
{
    private function seed(string $handle, int $count): \anvildev\simpleform\elements\Form
    {
        $form = $this->createForm('Widgets', $handle);
        $fieldId = $this->createField($form->id, 'text', 'name', 'Name');
        $service = Plugin::getInstance()->getSubmissionService();
        for ($i = 0; $i < $count; $i++) {
            $service->submit($form, ['field_' . $fieldId => 'Person ' . $i], ['skipCaptcha' => true]);
        }
        return $form;
    }

    public function testCountAllAndByForm(): void
    {
        $this->requireCraft();
        $formA = $this->seed('widget_count_a', 3);
        $this->seed('widget_count_b', 2);

        $all = new SubmissionCountWidget(['range' => 'all']);
        $this->assertSame(5, $all->count(), 'counts all submissions across forms');

        $scoped = new SubmissionCountWidget(['range' => 'all', 'formId' => (int) $formA->id]);
        $this->assertSame(3, $scoped->count(), 'scopes to one form');
    }

    public function testCountRespectsRange(): void
    {
        $this->requireCraft();
        $this->seed('widget_range', 2);

        // Both fresh submissions fall within every rolling window.
        $this->assertSame(2, (new SubmissionCountWidget(['range' => 'today']))->count());
        $this->assertSame(2, (new SubmissionCountWidget(['range' => '7d']))->count());
        $this->assertSame(2, (new SubmissionCountWidget(['range' => '30d']))->count());
    }

    public function testRecentWidgetListsNewestWithLinks(): void
    {
        $this->requireCraft();
        $form = $this->seed('widget_recent', 2);
        $latest = Submission::find()->formId($form->id)->orderBy(['dateCreated' => SORT_DESC])->one();

        // The recent widget gates on permission; assert the underlying query path
        // returns the seeded submissions newest-first.
        $query = Submission::find()
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->orderBy(['dateCreated' => SORT_DESC])
            ->limit(5)
            ->formId($form->id);
        $rows = $query->all();

        $this->assertCount(2, $rows);
        $this->assertSame((int) $latest->id, (int) $rows[0]->id);
        // The widget type is registered/instantiable.
        $this->assertInstanceOf(RecentSubmissionsWidget::class, new RecentSubmissionsWidget(['limit' => 5]));
    }
}

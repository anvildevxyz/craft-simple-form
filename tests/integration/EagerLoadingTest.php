<?php

namespace fabianhaef\simpleform\tests\integration;

use Craft;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\elements\Submission;

/**
 * @group requires-craft
 */
class EagerLoadingTest extends SimpleFormTestCase
{
    /**
     * Count the DB statements executed while running $fn by attaching a
     * temporary listener to the connection's PDO statement creation. This is
     * independent of Yii's profiling/logging config (which is flushed/disabled
     * under Codeception), so the count is always real. Caching is forced off
     * (devMode-on path) so the field-load queries are actually issued and the
     * bound is measured against real DB work, not cache hits.
     */
    private function countQueries(callable $fn): int
    {
        // Count at the PDO layer (independent of Yii's logging/profiling config,
        // which is unreliable under Codeception): install a counting
        // PDOStatement class so every prepared statement execution increments a
        // shared counter. SELECTs go through prepared statements, so this counts
        // the field-load query, the element queries, etc.
        $pdo = Craft::$app->getDb()->getMasterPdo();

        CountingPdoStatement::$count = 0;
        $previous = $pdo->getAttribute(\PDO::ATTR_STATEMENT_CLASS);
        $pdo->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [CountingPdoStatement::class]);

        try {
            $fn();
        } finally {
            // Restore the prior statement class (Yii's default is plain PDOStatement).
            $pdo->setAttribute(
                \PDO::ATTR_STATEMENT_CLASS,
                is_array($previous) && $previous !== [] ? $previous : [\PDOStatement::class]
            );
        }

        return CountingPdoStatement::$count;
    }

    public function testListingFormsLoadsFieldsInBoundedQueries(): void
    {
        $this->requireCraft();

        // Create several forms, each with a couple of fields.
        $forms = [];
        for ($i = 0; $i < 5; $i++) {
            $form = $this->createForm("Form $i", "boundedForm$i", "Form $i");
            $this->createField($form->id, 'text', 'a', 'Field A');
            $this->createField($form->id, 'email', 'b', 'Field B');
            $forms[] = $form;
        }

        $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        $formIds = array_map(fn($f) => $f->id, $forms);

        // Naive baseline: loading each form's fields individually issues one
        // query per form (the N+1 we're eliminating). This also proves the
        // query counter is live (non-zero).
        $listedNaive = Form::find()->siteId($siteId)->id($formIds)->all();
        $naive = $this->countQueries(function() use ($listedNaive): void {
            foreach ($listedNaive as $form) {
                \fabianhaef\simpleform\helpers\FieldQueryHelper::fieldsForForm((int)$form->id, (int)$form->siteId);
            }
        });
        $this->assertGreaterThanOrEqual(count($forms), $naive, 'Naive per-form load should be at least N queries');

        // Reload as a fresh listing so no field set is primed.
        $listed = Form::find()->siteId($siteId)->id($formIds)->all();
        $batched = $this->countQueries(function() use ($listed): void {
            Form::eagerLoadFields($listed);
            foreach ($listed as $form) {
                // Each access is query-free once eager-loaded.
                $this->assertNotEmpty($form->getFields());
            }
        });

        // Batched path: a single fields query (plus possible tiny overhead),
        // regardless of the number of forms — strictly fewer than the naive N.
        $this->assertLessThanOrEqual(2, $batched, "Expected bounded query count for 5 forms, got $batched");
        $this->assertLessThan($naive, $batched, 'Batched load must issue fewer queries than the naive N+1 path');
    }

    public function testEagerLoadingFormOnSubmissionsIsBounded(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Parent', 'parentForm', 'Parent Form');

        // Create several submissions referencing the same form.
        for ($i = 0; $i < 5; $i++) {
            $submission = new Submission();
            $submission->formId = $form->id;
            $submission->data = ['x' => $i];
            $saved = Craft::$app->getElements()->saveElement($submission);
            $this->assertTrue($saved, implode(', ', $submission->getFirstErrors()));
        }

        // Eager-load the parent form via Craft's standard .with() mechanism.
        $submissions = Submission::find()->formId($form->id)->with(['form'])->all();
        $this->assertCount(5, $submissions);

        $queries = $this->countQueries(function() use ($submissions, $form): void {
            foreach ($submissions as $submission) {
                $loaded = $submission->getForm();
                $this->assertNotNull($loaded);
                $this->assertSame($form->id, $loaded->id);
            }
        });

        // With eager-loading, accessing all parents costs 0 extra queries.
        $this->assertSame(0, $queries, "Expected 0 queries for eager-loaded forms, got $queries");
    }

    public function testSubmissionFormStillResolvesWithoutEagerLoading(): void
    {
        $this->requireCraft();

        $form = $this->createForm('Lazy', 'lazyForm', 'Lazy Form');
        $submission = new Submission();
        $submission->formId = $form->id;
        $submission->data = ['x' => 1];
        Craft::$app->getElements()->saveElement($submission);

        // No .with(['form']) — the lazy fallback query path must still work.
        $reloaded = Submission::find()->id($submission->id)->one();
        $this->assertNotNull($reloaded);
        $this->assertSame($form->id, $reloaded->getForm()?->id);
    }
}

/**
 * A PDOStatement that counts each execution into a shared static counter. Used
 * by {@see EagerLoadingTest::countQueries()} to measure real DB work.
 */
class CountingPdoStatement extends \PDOStatement
{
    public static int $count = 0;

    protected function __construct()
    {
        // PDOStatement's constructor is protected; nothing to initialise.
    }

    public function execute(?array $params = null): bool
    {
        self::$count++;
        return parent::execute($params);
    }
}

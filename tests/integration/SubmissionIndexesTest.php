<?php

namespace anvildev\simpleform\tests\integration;

use Craft;

/**
 * Scaling indexes on `simpleform_submissions` (#337). Asserts the fresh-install
 * schema (from the Install migration the test app runs) carries an index
 * covering each hot query shape. Name-agnostic: matches on the index's column
 * set so it holds across MySQL and Postgres.
 */
class SubmissionIndexesTest extends SimpleFormTestCase
{
    private const TABLE = '{{%simpleform_submissions}}';

    /** @return list<list<string>> */
    private function indexColumnSets(): array
    {
        $sets = [];
        foreach (Craft::$app->getDb()->getSchema()->getTableIndexes(self::TABLE) as $index) {
            $sets[] = array_values($index->columnNames);
        }

        return $sets;
    }

    /**
     * @dataProvider expectedIndexProvider
     * @param list<string> $columns
     */
    public function testExpectedIndexExists(array $columns): void
    {
        $this->requireCraft();
        $this->assertContains(
            $columns,
            $this->indexColumnSets(),
            'Expected an index on ' . implode(', ', $columns) . ' for scale.',
        );
    }

    /** @return array<string, array{0: list<string>}> */
    public static function expectedIndexProvider(): array
    {
        return [
            'form + status + date' => [['formId', 'readStatus', 'dateCreated']],
            'site + status' => [['siteId', 'readStatus']],
            'dateCreated' => [['dateCreated']],
            'ipHash' => [['ipHash']],
            'userId' => [['userId']],
            'paymentStatus' => [['paymentStatus']],
        ];
    }
}

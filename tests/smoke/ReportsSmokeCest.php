<?php

namespace anvildev\simpleform\tests\smoke;

use anvildev\simpleform\Plugin;
use Craft;
use SmokeTester;

/**
 * Reports + analytics smoke tests (#240): real public submissions feed the
 * survey field report and the analytics status breakdown, the same aggregations
 * the CP report and analytics dashboards render.
 *
 * @author Anvil Dev
 * @since 1.0.0
 */
class ReportsSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testFieldReportAggregatesChoiceCounts(SmokeTester $I): void
    {
        $form = $this->createForm('Survey', 'reportChoice' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'select', 'colour', 'Colour', false, [
            'options' => [
                ['label' => 'Red', 'value' => 'red'],
                ['label' => 'Green', 'value' => 'green'],
            ],
        ]);

        $this->submitRequest($form->handle, ['field_' . $fieldId => 'red']);
        $this->submitRequest($form->handle, ['field_' . $fieldId => 'red']);

        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $report = Plugin::getInstance()->getReports()->fieldReport($siteId, (int) $form->id);

        // The report is a list of rows keyed by `field_<id>`.
        $colour = null;
        foreach ($report as $row) {
            if (($row['key'] ?? null) === 'field_' . $fieldId) {
                $colour = $row;
            }
        }
        $I->assertNotNull($colour);
        $I->assertSame('choice', $colour['kind']);
        $I->assertSame(2, $colour['count']);

        $counts = array_column($colour['options'], 'count', 'value');
        $I->assertSame(2, $counts['red'] ?? null);
        $I->assertSame(0, $counts['green'] ?? null);
    }

    public function testResponseCountAndStatusBreakdownReflectSubmissions(SmokeTester $I): void
    {
        $form = $this->createForm('Survey', 'reportStatus' . uniqid());
        $fieldId = $this->createField((int) $form->id, 'text', 'name', 'Name');

        $this->submitRequest($form->handle, ['field_' . $fieldId => 'Ada']);
        $this->submitRequest($form->handle, ['field_' . $fieldId => 'Grace']);

        $reports = Plugin::getInstance()->getReports();
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;

        $I->assertSame(2, $reports->responseCount($siteId, (int) $form->id));

        $breakdown = $reports->statusBreakdown($siteId, (int) $form->id);
        $I->assertSame(2, $breakdown['total'] ?? null);
        $I->assertSame(2, $breakdown['new'] ?? null);
    }
}

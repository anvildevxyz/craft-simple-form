<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\widgets\SubmissionCountWidget;
use PHPUnit\Framework\TestCase;

class SubmissionCountWidgetTest extends TestCase
{
    public function testCutoffForToday(): void
    {
        $now = new \DateTimeImmutable('2026-06-18 15:30:00');
        $cutoff = SubmissionCountWidget::cutoffFor('today', $now);
        $this->assertSame('2026-06-18 00:00:00', $cutoff?->format('Y-m-d H:i:s'));
    }

    public function testCutoffForRollingWindows(): void
    {
        $now = new \DateTimeImmutable('2026-06-18 12:00:00');
        $this->assertSame('2026-06-11 12:00:00', SubmissionCountWidget::cutoffFor('7d', $now)?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-05-19 12:00:00', SubmissionCountWidget::cutoffFor('30d', $now)?->format('Y-m-d H:i:s'));
    }

    public function testCutoffForAllIsNull(): void
    {
        $this->assertNull(SubmissionCountWidget::cutoffFor('all', new \DateTimeImmutable()));
        $this->assertNull(SubmissionCountWidget::cutoffFor('bogus', new \DateTimeImmutable()));
    }
}

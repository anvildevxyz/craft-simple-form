<?php

namespace anvildev\simpleform\tests\unit;

use anvildev\simpleform\helpers\FormSteps;
use PHPUnit\Framework\TestCase;

class FormStepsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function field(string $name, mixed $page = null): array
    {
        $config = [];
        if ($page !== null) {
            $config['page'] = $page;
        }
        return ['name' => $name, 'config' => $config];
    }

    public function testNoPagesIsSingleStep(): void
    {
        $fields = [$this->field('a'), $this->field('b')];
        $steps = FormSteps::group($fields);
        $this->assertCount(1, $steps);
        $this->assertFalse(FormSteps::isMultiStep($fields));
    }

    public function testGroupsByPageInOrder(): void
    {
        $fields = [
            $this->field('a', 1),
            $this->field('b', 3),
            $this->field('c', 2),
            $this->field('d', 1),
        ];
        $steps = FormSteps::group($fields);

        $this->assertCount(3, $steps);
        $this->assertTrue(FormSteps::isMultiStep($fields));
        // Page 1 keeps a, d in order.
        $this->assertSame(['a', 'd'], array_column($steps[0], 'name'));
        $this->assertSame(['c'], array_column($steps[1], 'name'));
        $this->assertSame(['b'], array_column($steps[2], 'name'));
    }

    public function testInvalidPageFallsBackToOne(): void
    {
        $fields = [$this->field('a', 0), $this->field('b', 'x'), $this->field('c', 2)];
        $steps = FormSteps::group($fields);
        $this->assertCount(2, $steps);
        $this->assertSame(['a', 'b'], array_column($steps[0], 'name'));
    }
}

<?php

use Codeception\Actor;

/**
 * Back-compat alias for the smoke suite's actor.
 *
 * The smoke Cests were originally authored against a `FunctionalTester` that
 * drove the CP via a real browser (PhpBrowser). That browser actor is gone —
 * the functional smoke suite runs the public render/submit paths through a real
 * Craft (the `\craft\test\Craft` module) instead, so this alias simply exposes
 * the same generated module actions as {@see SmokeTester}. Cests should
 * type-hint `SmokeTester`; this alias remains only so any stray reference still
 * resolves to a clean, browser-free actor.
 */
class FunctionalTester extends Actor
{
    use _generated\SmokeTesterActions;
}

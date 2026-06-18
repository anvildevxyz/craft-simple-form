<?php

namespace fabianhaef\simpleform\tests\unit;

use fabianhaef\simpleform\elements\SubmissionStatus;
use PHPUnit\Framework\TestCase;

class SubmissionStatusTest extends TestCase
{
    public function testSpamConstant(): void
    {
        $this->assertSame('spam', SubmissionStatus::SPAM);
    }

    public function testSpamIsNotInTheToggleCycle(): void
    {
        $this->assertSame(['new', 'read', 'archived'], SubmissionStatus::all());
        $this->assertNotContains('spam', SubmissionStatus::all());
    }

    public function testAllValidIncludesSpam(): void
    {
        $this->assertContains('spam', SubmissionStatus::allValid());
        $this->assertSame(['new', 'read', 'archived', 'spam'], SubmissionStatus::allValid());
    }

    public function testIsValid(): void
    {
        $this->assertTrue(SubmissionStatus::isValid('spam'));
        $this->assertTrue(SubmissionStatus::isValid('new'));
        $this->assertFalse(SubmissionStatus::isValid('bogus'));
    }
}

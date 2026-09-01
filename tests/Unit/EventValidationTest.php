<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\services\SyncCoordinator;

/**
 * syncDailyEvents() batch-inserts a day's events in one transaction. A single malformed event
 * (missing/wrong-typed x or y) must be filtered out before that insert, not left to throw and
 * roll back every valid event for the day along with it.
 */
#[CoversClass(SyncCoordinator::class)]
class EventValidationTest extends TestCase
{
    private \ReflectionMethod $isValidEvent;

    protected function setUp(): void
    {
        $this->isValidEvent = new \ReflectionMethod(SyncCoordinator::class, '_isValidEvent');
    }

    public function testWellFormedEventIsValid(): void
    {
        $this->assertTrue($this->isValidEvent->invoke(null, ['x' => 'checkout', 'y' => 12]));
    }

    public function testMissingXIsInvalid(): void
    {
        $this->assertFalse($this->isValidEvent->invoke(null, ['y' => 12]));
    }

    public function testMissingYIsInvalid(): void
    {
        $this->assertFalse($this->isValidEvent->invoke(null, ['x' => 'checkout']));
    }

    public function testNonStringXIsInvalid(): void
    {
        $this->assertFalse($this->isValidEvent->invoke(null, ['x' => ['nested' => 'object'], 'y' => 12]));
    }

    public function testNonNumericYIsInvalid(): void
    {
        $this->assertFalse($this->isValidEvent->invoke(null, ['x' => 'checkout', 'y' => 'not-a-number']));
    }

    public function testNonArrayEventIsInvalid(): void
    {
        $this->assertFalse($this->isValidEvent->invoke(null, 'checkout'));
    }
}

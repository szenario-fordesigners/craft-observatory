<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\controllers\DashboardController;

/**
 * The KPI trend percentages are only meaningful against a like-for-like span. A prior window
 * of the wrong length silently skews every trend on the dashboard, and one sharing a boundary
 * instant with the current window double-counts the event on that boundary.
 */
#[CoversClass(DashboardController::class)]
class ComparisonRangeTest extends TestCase
{
    private \ReflectionMethod $comparisonRange;

    protected function setUp(): void
    {
        // comparisonRange() is pure, so the controller constructor (and Craft::$app) can be skipped.
        $this->comparisonRange = new \ReflectionMethod(DashboardController::class, 'comparisonRange');
    }

    public function testPriorWindowMatchesTheRequestedSpan(): void
    {
        $start = 1718409600000;
        $end = $start + 7 * 86400000;

        [$priorStart, $priorEnd] = $this->rangeFor($start, $end);

        $this->assertSame($end - $start, $priorEnd - $priorStart, 'prior span must equal current span');
    }

    public function testPriorWindowEndsBeforeTheCurrentOneBegins(): void
    {
        $start = 1718409600000;

        [, $priorEnd] = $this->rangeFor($start, $start + 86400000);

        // getTotals() bounds are inclusive on both ends, so sharing $start would count the
        // event on that instant in both windows.
        $this->assertLessThan($start, $priorEnd);
    }

    public function testPriorWindowImmediatelyPrecedesTheCurrentOne(): void
    {
        $start = 1718409600000;

        [, $priorEnd] = $this->rangeFor($start, $start + 30 * 86400000);

        // Adjacent, not merely earlier: a gap would drop traffic out of the comparison.
        $this->assertSame($start - 1, $priorEnd);
    }

    public function testInvertedRangeDoesNotProduceAWindowInTheFuture(): void
    {
        $start = 1718409600000;

        // An endAt before startAt is malformed input; it must not yield a prior window that
        // reaches forward past $startAt and overlaps the current one.
        [$priorStart, $priorEnd] = $this->rangeFor($start, $start - 86400000);

        $this->assertLessThan($start, $priorStart);
        $this->assertLessThan($start, $priorEnd);
        $this->assertLessThanOrEqual($priorEnd, $priorStart);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function rangeFor(int $startAt, int $endAt): array
    {
        return $this->comparisonRange->invoke(
            (new \ReflectionClass(DashboardController::class))->newInstanceWithoutConstructor(),
            $startAt,
            $endAt,
        );
    }
}

<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\services\StatsReport;

/**
 * The heatmap averages visitors per (weekday, hour) over the days that were *observed*, not over
 * the days that happened to have traffic.
 *
 * The mirror stores no row for an hour with no visitors, so dividing by rows-found divides by
 * "Mondays where 09:00 was busy". On live data that overstated 34 of 36 cells, most by 5×, and
 * made a one-off spike render as hot as a consistently busy hour. These tests pin the divisor and
 * the 0-based weekday conversion — an off-by-one there silently rotates the entire grid.
 */
#[CoversClass(StatsReport::class)]
class HeatmapAverageTest extends TestCase
{
    private \ReflectionMethod $weekdayOf;
    private \ReflectionMethod $tallyMethod;

    protected function setUp(): void
    {
        $this->weekdayOf = new \ReflectionMethod(StatsReport::class, '_weekdayOf');
        $this->tallyMethod = new \ReflectionMethod(StatsReport::class, '_weekdayTally');
    }

    public function testMondayIsZeroAndSundayIsSix(): void
    {
        // 2026-08-24 is a Monday.
        $this->assertSame(0, $this->weekday('2026-08-24'), 'Monday must be 0');
        $this->assertSame(6, $this->weekday('2026-08-30'), 'Sunday must be 6');
    }

    public function testEveryWeekdayMapsToADistinctIndexInRange(): void
    {
        $seen = [];
        foreach (range(24, 30) as $day) {
            $seen[] = $this->weekday(sprintf('2026-08-%02d', $day));
        }

        $this->assertSame([0, 1, 2, 3, 4, 5, 6], $seen);
    }

    public function testWeekdayIsStableAcrossADstTransition(): void
    {
        // 2026-03-29 is Berlin's spring-forward Sunday; the calendar weekday is unaffected.
        $this->assertSame(6, $this->weekday('2026-03-29'));
        $this->assertSame(0, $this->weekday('2026-03-30'));
    }

    public function testWeekdayIsStableAcrossAYearBoundary(): void
    {
        $this->assertSame(3, $this->weekday('2026-12-31')); // Thursday
        $this->assertSame(4, $this->weekday('2027-01-01')); // Friday
    }

    public function testTallyCountsEachWeekdayOccurrence(): void
    {
        // A 30-day window ending Sunday 2026-08-30: 5 Mondays and 5 Sundays, 4 of everything else.
        $dates = [];
        $cursor = new \DateTimeImmutable('2026-08-01');
        for ($i = 0; $i < 30; $i++) {
            $dates[] = $cursor->modify("+{$i} days")->format('Y-m-d');
        }

        $tally = $this->tally($dates);

        $this->assertSame(30, array_sum($tally), 'every date must land in exactly one bucket');
        $this->assertCount(7, $tally);
        foreach ($tally as $weekday => $count) {
            $this->assertGreaterThanOrEqual(4, $count, "weekday {$weekday}");
            $this->assertLessThanOrEqual(5, $count, "weekday {$weekday}");
        }
    }

    public function testTallyAlwaysReportsAllSevenWeekdaysEvenWhenUnobserved(): void
    {
        // A missing weekday must be 0, not absent: the caller indexes into this by weekday and a
        // missing key would make the cell fall through its divide-by-zero guard and disappear.
        $tally = $this->tally(['2026-08-24', '2026-08-24', '2026-08-31']);

        $this->assertSame(3, $tally[0], 'duplicates are not deduplicated here');
        $this->assertSame(0, $tally[1]);
        $this->assertSame(array_keys(array_fill(0, 7, 0)), array_keys($tally));
    }

    public function testEmptyWindowTalliesToAllZeroes(): void
    {
        $this->assertSame(array_fill(0, 7, 0), $this->tally([]));
    }

    private function weekday(string $date): int
    {
        return $this->weekdayOf->invoke(null, $date);
    }

    /** @return array<int,int> */
    private function tally(array $dates): array
    {
        return $this->tallyMethod->invoke(null, $dates);
    }
}

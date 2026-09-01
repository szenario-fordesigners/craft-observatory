<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\helpers\AnalyticsTime;

/**
 * A KPI trend is only meaningful if the two windows sit at the same *phase*, not merely if they
 * are the same length.
 *
 * The comparison used to be the window immediately preceding the current one, derived by
 * subtracting its elapsed length. Because every day-aligned preset starts at midnight but is
 * truncated at "now", that put the prior window at an arbitrary time of day: at 15:00 on a
 * Wednesday, "today" compared this morning against yesterday *evening*, and "this week" compared
 * Mon–Wed against Fri–Sun. Every arrow on the dashboard was skewed, and systematically so.
 *
 * `compareStartAt` is therefore stepped back one whole period in calendar units.
 */
#[CoversClass(AnalyticsTime::class)]
class ComparisonRangeTest extends TestCase
{
    private \DateTimeZone $tz;

    /** Wednesday, mid-afternoon — a partly-elapsed day, week, month and year at once. */
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->tz = new \DateTimeZone('Europe/Berlin');
        $this->now = new \DateTimeImmutable('2026-08-12 15:00:00', $this->tz);
    }

    public function testTodayComparesAgainstYesterdayFromMidnight(): void
    {
        // Not "the 15 hours before this window", which was yesterday 09:00–24:00.
        $this->assertSame('Tue 2026-08-11 00:00:00', $this->compareStart('today'));
    }

    public function testThisWeekComparesAgainstThePreviousMondayNotTheWeekend(): void
    {
        $this->assertSame('Mon 2026-08-03 00:00:00', $this->compareStart('this_week'));
    }

    public function testThisMonthComparesAgainstTheFirstOfThePreviousMonth(): void
    {
        // Calendar arithmetic, not a fixed 30/31-day subtraction.
        $this->assertSame('Wed 2026-07-01 00:00:00', $this->compareStart('this_month'));
    }

    public function testThisYearComparesAgainstJanuaryFirstOfThePreviousYear(): void
    {
        $this->assertSame('Wed 2025-01-01 00:00:00', $this->compareStart('this_year'));
    }

    public function testRollingPresetsStepBackByTheirOwnSpan(): void
    {
        // 7d covers today-6..today, so its prior period starts 7 days before that.
        $this->assertSame('Thu 2026-07-30 00:00:00', $this->compareStart('7d'));
        $this->assertSame('Sun 2026-06-14 00:00:00', $this->compareStart('30d'));
    }

    public function testMonthPresetsAnchorToTheFirstOfTheMonth(): void
    {
        // Anchored to the 1st so subtracting months can't overflow into the wrong month.
        // 6m spans Mar–Aug 2026, so its prior period starts six months before March.
        $this->assertSame('Mon 2025-09-01 00:00:00', $this->compareStart('6m'));
        $this->assertSame('Sun 2024-09-01 00:00:00', $this->compareStart('12m'));
    }

    public function testRollingDayComparesAgainstTheDayBeforeIt(): void
    {
        $range = $this->range('24h');

        // The only preset that is not day-aligned: exactly 24h earlier, to the second.
        $this->assertSame($range['startAt'] - 86400000, $range['compareStartAt']);
    }

    public function testEveryPresetsPriorPeriodBeginsBeforeTheCurrentOne(): void
    {
        foreach (['today', '24h', 'this_week', '7d', 'this_month', '30d', '90d', 'this_year', '6m', '12m'] as $preset) {
            $range = $this->range($preset);

            $this->assertLessThan(
                $range['startAt'],
                $range['compareStartAt'],
                "{$preset}: prior period must begin before the current one",
            );
        }
    }

    public function testDstMakesTheElapsedSpanOvershootWhichIsWhyTheCallerClamps(): void
    {
        // The anchor is a calendar step back, but the span added to it is a millisecond count, so
        // the two can disagree: 90d covers May 15–Aug 12 (no transition) while its prior period
        // covers Feb 14–May 15, which contains Berlin's spring-forward and is therefore an hour
        // shorter in real time. Adding the current elapsed span overshoots into the current
        // window, which is exactly why totalsWithComparison() clamps the end below startAt.
        $range = $this->range('90d');
        $overshoot = ($range['compareStartAt'] + ($range['endAt'] - $range['startAt'])) - $range['startAt'];

        $this->assertSame(3599000, $overshoot, 'one hour, less the inclusive-end second');
        $this->assertLessThan(
            $range['startAt'],
            min($range['compareStartAt'] + ($range['endAt'] - $range['startAt']), $range['startAt'] - 1),
            'the clamp must bring it back inside',
        );
    }

    public function testCustomRangeComparesAgainstTheSameNumberOfDaysBeforeIt(): void
    {
        $range = AnalyticsTime::customRange('2026-08-10', '2026-08-12', $this->tz);

        // A 3-day range compares against the 3 days before it.
        $this->assertSame('Fri 2026-08-07 00:00:00', $this->format($range['compareStartAt']));
    }

    public function testDstTransitionKeepsTheComparisonOnLocalMidnight(): void
    {
        // 2026-03-29 is the Berlin spring-forward day. Stepping back a week in calendar units
        // must still land on local midnight, which a fixed 7×86400s subtraction would miss by an
        // hour and push into the previous day.
        $now = new \DateTimeImmutable('2026-03-31 12:00:00', $this->tz);
        $range = AnalyticsTime::presetRange('this_week', $this->tz, $now);

        $this->assertSame('Mon 2026-03-23 00:00:00', $this->format($range['compareStartAt']));
    }

    /**
     * @return array{startAt:int,endAt:int,unit:string,compareStartAt:int}
     */
    private function range(string $preset): array
    {
        $range = AnalyticsTime::presetRange($preset, $this->tz, $this->now);
        $this->assertNotNull($range, "preset {$preset} must resolve");

        return $range;
    }

    private function compareStart(string $preset): string
    {
        return $this->format($this->range($preset)['compareStartAt']);
    }

    private function format(int $ms): string
    {
        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))
            ->setTimezone($this->tz)
            ->format('D Y-m-d H:i:s');
    }
}

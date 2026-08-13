<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\helpers\AnalyticsTime;

/**
 * Dashboard windows are resolved server-side, in the site timezone, because the mirror's day
 * keys are bucketed in that same zone. Deriving them from the viewer's browser clock instead
 * meant "Today" in a CP session abroad selected a shifted window and then read the wrong day
 * rows back out of the mirror.
 */
#[CoversClass(AnalyticsTime::class)]
class RangeResolutionTest extends TestCase
{
    private \DateTimeZone $berlin;
    private \DateTimeZone $kiritimati;

    protected function setUp(): void
    {
        $this->berlin = new \DateTimeZone('Europe/Berlin');
        $this->kiritimati = new \DateTimeZone('Pacific/Kiritimati');
    }

    /**
     * The whole point of the change: the window follows the *site's* zone, so it must not be
     * possible for two zones to resolve the same calendar day to the same instants.
     */
    public function testPresetsResolveAgainstTheGivenZone(): void
    {
        $berlin = AnalyticsTime::presetRange('today', $this->berlin, $this->dayIn('2026-08-13', $this->berlin));
        $kiritimati = AnalyticsTime::presetRange('today', $this->kiritimati, $this->dayIn('2026-08-13', $this->kiritimati));

        $this->assertNotNull($berlin);
        $this->assertNotNull($kiritimati);
        $this->assertNotSame($berlin['startAt'], $kiritimati['startAt']);

        $this->assertLocalMidnight($berlin['startAt'], '2026-08-13', $this->berlin);
        $this->assertLocalMidnight($kiritimati['startAt'], '2026-08-13', $this->kiritimati);
    }

    public function testTodayCoversExactlyOneLocalDay(): void
    {
        $range = AnalyticsTime::presetRange('today', $this->berlin, $this->dayIn('2026-08-13', $this->berlin));

        $this->assertNotNull($range);
        $this->assertSame(86400 - 1, intdiv($range['endAt'] - $range['startAt'], 1000));
    }

    /**
     * A fixed ±24h assumption would silently truncate or overrun the DST days.
     */
    public function testDstDaysAreBoundedByTheirLocalMidnights(): void
    {
        foreach (['2026-03-29' => 23, '2026-10-25' => 25] as $date => $expectedHours) {
            $range = AnalyticsTime::presetRange('today', $this->berlin, $this->dayIn($date, $this->berlin));

            $this->assertNotNull($range);
            $this->assertSame(
                $expectedHours * 3600 - 1,
                intdiv($range['endAt'] - $range['startAt'], 1000),
                "span of {$date}",
            );
            $this->assertLocalMidnight($range['startAt'], $date, $this->berlin);
        }
    }

    /**
     * Subtracting months from the 31st overflows into the following month in PHP (Aug 31 minus
     * 6 months lands on Mar 3), so the month presets must anchor to the 1st before doing the
     * arithmetic or the window starts in the wrong month.
     */
    public function testMonthPresetsDoNotOverflowFromALongMonth(): void
    {
        $endOfAugust = $this->dayIn('2026-08-31', $this->berlin);

        $sixMonths = AnalyticsTime::presetRange('6m', $this->berlin, $endOfAugust);
        $twelveMonths = AnalyticsTime::presetRange('12m', $this->berlin, $endOfAugust);

        $this->assertNotNull($sixMonths);
        $this->assertNotNull($twelveMonths);
        $this->assertLocalMidnight($sixMonths['startAt'], '2026-03-01', $this->berlin);
        $this->assertLocalMidnight($twelveMonths['startAt'], '2025-09-01', $this->berlin);
    }

    public function testThisMonthSpansTheWholeCalendarMonth(): void
    {
        $range = AnalyticsTime::presetRange('this_month', $this->berlin, $this->dayIn('2026-02-13', $this->berlin));

        $this->assertNotNull($range);
        $this->assertLocalMidnight($range['startAt'], '2026-02-01', $this->berlin);
        // 2026 is not a leap year, so February ends on the 28th.
        $this->assertSame('2026-02-28 23:59:59', $this->localTime($range['endAt'], $this->berlin));
    }

    /**
     * Monday-first, and stable no matter which weekday the request lands on — including the
     * boundary days, where a naive "last monday" would jump a week.
     */
    public function testThisWeekIsAlwaysMondayToSunday(): void
    {
        foreach (['2026-08-10', '2026-08-13', '2026-08-16'] as $date) {
            $range = AnalyticsTime::presetRange('this_week', $this->berlin, $this->dayIn($date, $this->berlin));

            $this->assertNotNull($range);
            $this->assertLocalMidnight($range['startAt'], '2026-08-10', $this->berlin);
            $this->assertSame('2026-08-16 23:59:59', $this->localTime($range['endAt'], $this->berlin), "week of {$date}");
        }
    }

    /**
     * The preset names are a contract with the CP date picker (PRESET_RANGES in
     * useDateRange.ts). A name the backend does not know silently falls back to the default
     * window, so every offered preset has to resolve.
     */
    public function testEveryPresetOfferedByThePickerResolves(): void
    {
        $offered = ['today', '24h', 'this_week', '7d', 'this_month', '30d', '90d', 'this_year', '6m', '12m'];

        foreach ($offered as $preset) {
            $range = AnalyticsTime::presetRange($preset, $this->berlin, $this->dayIn('2026-08-13', $this->berlin));

            $this->assertNotNull($range, "preset {$preset} did not resolve");
            $this->assertLessThan($range['endAt'], $range['startAt'], "preset {$preset} is not a forward range");
            $this->assertContains($range['unit'], ['hour', 'day', 'month', 'year'], "preset {$preset} unit");
        }
    }

    public function testUnknownPresetIsRejected(): void
    {
        $this->assertNull(AnalyticsTime::presetRange('last_fortnight', $this->berlin));
    }

    /**
     * Custom dates arrive straight off a query string, so they are a trust boundary: anything
     * that is not a real calendar date must be refused rather than coerced. createFromFormat
     * would happily roll 2026-02-31 forward into March.
     */
    public function testCustomRangeRejectsAnythingThatIsNotARealDate(): void
    {
        foreach (['2026-02-31', '2026-8-1', '', 'today', '2026-13-01', '13/08/2026'] as $bad) {
            $this->assertNull(
                AnalyticsTime::customRange($bad, '2026-08-13', $this->berlin),
                "accepted bad start date '{$bad}'",
            );
            $this->assertNull(
                AnalyticsTime::customRange('2026-08-13', $bad, $this->berlin),
                "accepted bad end date '{$bad}'",
            );
        }
    }

    public function testCustomRangeIsInclusiveOfBothDatesInSiteZone(): void
    {
        $range = AnalyticsTime::customRange('2026-08-01', '2026-08-07', $this->berlin);

        $this->assertNotNull($range);
        $this->assertLocalMidnight($range['startAt'], '2026-08-01', $this->berlin);
        $this->assertSame('2026-08-07 23:59:59', $this->localTime($range['endAt'], $this->berlin));
    }

    public function testCustomRangeNormalisesInvertedInput(): void
    {
        $forwards = AnalyticsTime::customRange('2026-08-01', '2026-08-07', $this->berlin);
        $backwards = AnalyticsTime::customRange('2026-08-07', '2026-08-01', $this->berlin);

        $this->assertSame($forwards, $backwards);
    }

    public function testCustomRangeGranularityFollowsItsSpan(): void
    {
        $cases = [
            ['2026-08-01', '2026-08-02', 'hour'],
            ['2026-08-01', '2026-08-30', 'day'],
            ['2026-01-01', '2027-06-01', 'month'],
        ];

        foreach ($cases as [$start, $end, $expected]) {
            $range = AnalyticsTime::customRange($start, $end, $this->berlin);

            $this->assertNotNull($range);
            $this->assertSame($expected, $range['unit'], "{$start}..{$end}");
        }
    }

    private function dayIn(string $date, \DateTimeZone $tz): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' 00:00:00', $tz);
    }

    private function localTime(int $ms, \DateTimeZone $tz): string
    {
        return (new \DateTimeImmutable('@' . intdiv($ms, 1000)))->setTimezone($tz)->format('Y-m-d H:i:s');
    }

    private function assertLocalMidnight(int $ms, string $expectedDate, \DateTimeZone $tz): void
    {
        $this->assertSame($expectedDate . ' 00:00:00', $this->localTime($ms, $tz));
    }
}

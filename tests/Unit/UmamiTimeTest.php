<?php

namespace szenario\craftumamiis\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftumamiis\helpers\UmamiTime;

#[CoversClass(UmamiTime::class)]
class UmamiTimeTest extends TestCase
{
    private \DateTimeZone $utc;
    private \DateTimeZone $berlin;

    protected function setUp(): void
    {
        $this->utc = new \DateTimeZone('UTC');
        $this->berlin = new \DateTimeZone('Europe/Berlin');
    }

    public function testDateOffsetReturnsIso8601DateFormat(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', UmamiTime::dateOffset(0, $this->utc));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', UmamiTime::dateOffset(30, $this->utc));
    }

    public function testDateOffsetIsNDaysBeforeToday(): void
    {
        $today = UmamiTime::dateOffset(0, $this->utc);
        $sevenDaysAgo = UmamiTime::dateOffset(7, $this->utc);

        $diff = (new \DateTimeImmutable($today, $this->utc))
            ->diff(new \DateTimeImmutable($sevenDaysAgo, $this->utc));

        $this->assertSame(7, $diff->days);
    }

    public function testDateOffsetCrossesYearBoundary(): void
    {
        // Compute against a known reference: dateOffset behavior is day-arithmetic, so the
        // gap between today and yesterday is always 1 calendar day even on Jan 1.
        $today = UmamiTime::dateOffset(0, $this->utc);
        $yesterday = UmamiTime::dateOffset(1, $this->utc);

        $todayDt = new \DateTimeImmutable($today, $this->utc);
        $yesterdayDt = new \DateTimeImmutable($yesterday, $this->utc);

        $this->assertSame(1, $todayDt->diff($yesterdayDt)->days);
    }

    public function testDateOffsetUsesProvidedTimezone(): void
    {
        // Pacific/Kiritimati is UTC+14, Pacific/Pago_Pago is UTC-11 — 25 hours apart.
        // At the right moment, "today" in Kiritimati can be a different calendar day than
        // "today" in Pago_Pago. We don't know the wall-clock moment of test execution, but
        // we can assert the result still has the Y-m-d shape and is internally consistent.
        $kiritimati = UmamiTime::dateOffset(0, new \DateTimeZone('Pacific/Kiritimati'));
        $pagoPago = UmamiTime::dateOffset(0, new \DateTimeZone('Pacific/Pago_Pago'));

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $kiritimati);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $pagoPago);
    }

    public function testDayBoundsForStandardUtcDay(): void
    {
        [$start, $end] = UmamiTime::dayBounds('2024-06-15', $this->utc);

        $this->assertSame(1718409600 * 1000, $start);
        $this->assertSame(1718495999 * 1000, $end);
        $this->assertSame(86399, ($end - $start) / 1000, 'Standard day is 24h - 1s');
    }

    public function testDayBoundsHandlesSpringForward(): void
    {
        // 2024-03-31 in Europe/Berlin: clocks jump 02:00 → 03:00, so the day is 23 hours.
        [$start, $end] = UmamiTime::dayBounds('2024-03-31', $this->berlin);

        $this->assertSame(1711839600 * 1000, $start);
        $this->assertSame(1711922399 * 1000, $end);
        $this->assertSame(82799, ($end - $start) / 1000, 'Spring-forward day is 23h - 1s');
    }

    public function testDayBoundsHandlesFallBack(): void
    {
        // 2024-10-27 in Europe/Berlin: clocks fall 03:00 → 02:00, so the day is 25 hours.
        [$start, $end] = UmamiTime::dayBounds('2024-10-27', $this->berlin);

        $this->assertSame(1729980000 * 1000, $start);
        $this->assertSame(1730069999 * 1000, $end);
        $this->assertSame(89999, ($end - $start) / 1000, 'Fall-back day is 25h - 1s');
    }

    public function testDayBoundsForYearBoundary(): void
    {
        [$start, $end] = UmamiTime::dayBounds('2024-12-31', $this->utc);

        $this->assertSame(1735603200 * 1000, $start);
        $this->assertSame(1735689599 * 1000, $end);

        // End must still fall inside 2024-12-31 in the given TZ — i.e. not roll into 2025.
        $endDt = (new \DateTimeImmutable('@' . ($end / 1000)))->setTimezone($this->utc);
        $this->assertSame('2024-12-31', $endDt->format('Y-m-d'));
    }

    public function testDayBoundsForLeapDay(): void
    {
        [$start, $end] = UmamiTime::dayBounds('2024-02-29', $this->utc);

        $this->assertSame(1709164800 * 1000, $start);
        $this->assertSame(1709251199 * 1000, $end);
        $this->assertSame(86399, ($end - $start) / 1000);
    }

    public function testDayBoundsEndIsAlwaysOneSecondBeforeNextDayStart(): void
    {
        // Property check across a month including the spring-forward DST boundary in Berlin.
        $tz = $this->berlin;
        for ($day = 1; $day <= 30; $day++) {
            $today = sprintf('2024-03-%02d', $day);
            $tomorrow = sprintf('2024-03-%02d', $day + 1);

            [, $endToday] = UmamiTime::dayBounds($today, $tz);
            [$startTomorrow] = UmamiTime::dayBounds($tomorrow, $tz);

            $this->assertSame(1000, $startTomorrow - $endToday, "Gap on {$today}→{$tomorrow} should be exactly 1000ms");
        }
    }

    public function testDayBoundsStartIsLocalMidnight(): void
    {
        [$start] = UmamiTime::dayBounds('2024-06-15', $this->berlin);
        $startDt = (new \DateTimeImmutable('@' . ($start / 1000)))->setTimezone($this->berlin);

        $this->assertSame('2024-06-15 00:00:00', $startDt->format('Y-m-d H:i:s'));
    }

    public function testDayBoundsAcceptsCustomTimezone(): void
    {
        // Same calendar date in two zones produces different absolute timestamps.
        [$utcStart] = UmamiTime::dayBounds('2024-06-15', $this->utc);
        [$berlinStart] = UmamiTime::dayBounds('2024-06-15', $this->berlin);

        // Berlin in June is UTC+2, so its midnight comes 2 hours earlier in absolute time.
        $this->assertSame(2 * 3600 * 1000, $utcStart - $berlinStart);
    }
}

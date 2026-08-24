<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\helpers\AnalyticsTime;
use szenario\craftobservatory\services\StatsReport;

/**
 * The hourly mirror is selected by date, so the first and last day of a window arrive whole even
 * when the window covers only part of them. Every preset used to be day-aligned and this did not
 * matter; the rolling "Last 24 hours" window is not, and without clamping it would report 24 to 48
 * hours of data — the very thing making that window rolling was meant to fix.
 */
#[CoversClass(StatsReport::class)]
class MirrorHourClampTest extends TestCase
{
    private \DateTimeZone $tz;
    private StatsReport $stats;
    private \ReflectionMethod $hourStartsWithin;

    protected function setUp(): void
    {
        $this->tz = new \DateTimeZone('Europe/Berlin');
        // _hourStartsWithin() is pure, so the service constructor (and Craft::$app) can be skipped.
        $this->stats = (new \ReflectionClass(StatsReport::class))->newInstanceWithoutConstructor();
        $this->hourStartsWithin = new \ReflectionMethod(StatsReport::class, '_hourStartsWithin');
    }

    public function testRollingWindowKeepsExactlyTwentyFourHourlyBuckets(): void
    {
        $range = $this->rollingNoon();

        $kept = [];
        foreach (['2026-08-12', '2026-08-13'] as $date) {
            for ($hour = 0; $hour < 24; $hour++) {
                if ($this->within($date, $hour, $range)) {
                    $kept[] = sprintf('%s %02d', $date, $hour);
                }
            }
        }

        $this->assertCount(24, $kept);
        $this->assertSame('2026-08-12 12', $kept[0]);
        $this->assertSame('2026-08-13 11', end($kept));
    }

    /**
     * The bucket starting exactly at endAt covers the hour *after* the window, so including it
     * would report 25 hours for a 24-hour range.
     */
    public function testBucketStartingAtTheWindowEndIsExcluded(): void
    {
        $range = $this->rollingNoon();

        $this->assertTrue($this->within('2026-08-13', 11, $range), 'last hour inside the window');
        $this->assertFalse($this->within('2026-08-13', 12, $range), 'hour starting at endAt');
    }

    public function testHoursBeforeTheRollingStartAreDropped(): void
    {
        $range = $this->rollingNoon();

        $this->assertFalse($this->within('2026-08-12', 11, $range));
        $this->assertTrue($this->within('2026-08-12', 12, $range));
    }

    /**
     * Day-aligned windows must be completely unaffected — the clamp is there for the rolling
     * preset alone, and silently trimming an hour off "Today" would be a regression.
     */
    public function testDayAlignedWindowsKeepEveryHour(): void
    {
        $today = AnalyticsTime::presetRange('today', $this->tz, new \DateTimeImmutable('2026-08-13 23:59:00', $this->tz));
        $custom = AnalyticsTime::customRange('2026-08-11', '2026-08-12', $this->tz);

        $this->assertNotNull($today);
        $this->assertNotNull($custom);

        for ($hour = 0; $hour < 24; $hour++) {
            $this->assertTrue($this->within('2026-08-13', $hour, $today), "today hour {$hour}");
            $this->assertTrue($this->within('2026-08-11', $hour, $custom), "custom day 1 hour {$hour}");
            $this->assertTrue($this->within('2026-08-12', $hour, $custom), "custom day 2 hour {$hour}");
        }
    }

    /**
     * @return array{startAt:int,endAt:int,unit:string}
     */
    private function rollingNoon(): array
    {
        $range = AnalyticsTime::presetRange(
            '24h',
            $this->tz,
            new \DateTimeImmutable('2026-08-13 12:00:00', $this->tz),
        );

        $this->assertNotNull($range);

        return $range;
    }

    /**
     * @param array{startAt:int,endAt:int,unit:string} $range
     */
    private function within(string $date, int $hour, array $range): bool
    {
        return (bool) $this->hourStartsWithin->invoke(
            $this->stats,
            $date,
            $hour,
            $range['startAt'],
            $range['endAt'],
            $this->tz,
        );
    }
}

<?php

namespace szenario\craftobservatory\tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use szenario\craftobservatory\helpers\AnalyticsTime;
use szenario\craftobservatory\sources\PostHogAnalyticsSource;

/**
 * HogQL parses bare datetime literals in the *PostHog project's* timezone, which Craft has
 * no way to know. Query window bounds must therefore be emitted as absolute instants, or
 * they drift against the day/hour buckets — which are grouped in Craft's timezone — and
 * silently truncate every synced day.
 */
#[CoversClass(PostHogAnalyticsSource::class)]
class PostHogWindowBoundsTest extends TestCase
{
    private PostHogAnalyticsSource $source;
    private \ReflectionMethod $toDateTime;

    protected function setUp(): void
    {
        // _toDateTime() is pure, so the constructor (and Craft::$app) can be skipped.
        $this->source = (new \ReflectionClass(PostHogAnalyticsSource::class))
            ->newInstanceWithoutConstructor();
        $this->toDateTime = new \ReflectionMethod(PostHogAnalyticsSource::class, '_toDateTime');
    }

    public function testBoundIsEmittedAsAnAbsoluteEpoch(): void
    {
        $this->assertSame('fromUnixTimestamp(1718409600)', $this->expr(1718409600000));
    }

    public function testBoundCarriesNoQuotedDatetimeLiteral(): void
    {
        $expr = $this->expr(1718409600000);

        // A quoted literal is what HogQL re-parses in the project's timezone; toDateTime()
        // is additionally flagged tz_aware, so it appends that zone on its own.
        $this->assertStringNotContainsString("'", $expr);
        $this->assertStringNotContainsString('toDateTime', $expr);
    }

    public function testSubSecondMillisecondsAreFlooredNotRounded(): void
    {
        // 23:59:59.999 must stay inside the day it bounds, never roll into the next one.
        $this->assertSame('fromUnixTimestamp(1718495999)', $this->expr(1718495999999));
    }

    /**
     * The bound must denote the same wall-clock instant Craft asked for, in whatever zone
     * Craft is configured to — independent of the PostHog project's own timezone.
     */
    public function testDayBoundsRoundTripToLocalMidnightInAnyZone(): void
    {
        foreach (['UTC', 'Europe/Berlin', 'US/Pacific', 'Pacific/Kiritimati'] as $zone) {
            $tz = new \DateTimeZone($zone);
            [$startMs, $endMs] = AnalyticsTime::dayBounds('2024-06-15', $tz);

            $start = (new \DateTimeImmutable('@' . $this->epochOf($this->expr($startMs))))->setTimezone($tz);
            $end = (new \DateTimeImmutable('@' . $this->epochOf($this->expr($endMs))))->setTimezone($tz);

            $this->assertSame('2024-06-15 00:00:00', $start->format('Y-m-d H:i:s'), "start bound in {$zone}");
            $this->assertSame('2024-06-15 23:59:59', $end->format('Y-m-d H:i:s'), "end bound in {$zone}");
        }
    }

    public function testSameLocalDayInDifferentZonesYieldsDifferentInstants(): void
    {
        // Guards the inverse mistake: pinning the bounds to one fixed zone would make these
        // identical and reintroduce the offset bug from the other direction.
        [$berlin] = AnalyticsTime::dayBounds('2024-06-15', new \DateTimeZone('Europe/Berlin'));
        [$pacific] = AnalyticsTime::dayBounds('2024-06-15', new \DateTimeZone('US/Pacific'));

        $this->assertNotSame($this->expr($berlin), $this->expr($pacific));
    }

    public function testDstBoundsStayWithinTheirLocalDay(): void
    {
        $tz = new \DateTimeZone('Europe/Berlin');

        // Spring-forward (23h) and fall-back (25h) days: the emitted instants must still
        // land on the date they bound, which a fixed ±offset assumption would get wrong.
        foreach (['2024-03-31', '2024-10-27'] as $date) {
            [$startMs, $endMs] = AnalyticsTime::dayBounds($date, $tz);

            foreach ([$startMs, $endMs] as $ms) {
                $instant = (new \DateTimeImmutable('@' . $this->epochOf($this->expr($ms))))->setTimezone($tz);
                $this->assertSame($date, $instant->format('Y-m-d'), "bound escaped {$date}");
            }
        }
    }

    private function expr(int $ms): string
    {
        return $this->toDateTime->invoke($this->source, $ms);
    }

    private function epochOf(string $expr): int
    {
        $this->assertMatchesRegularExpression('/^fromUnixTimestamp\(\d+\)$/', $expr);

        return (int) filter_var($expr, FILTER_SANITIZE_NUMBER_INT);
    }
}

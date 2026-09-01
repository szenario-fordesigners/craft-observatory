<?php

namespace szenario\craftobservatory\helpers;

use Craft;
use craft\helpers\App;

/**
 * Date helpers tied to a timezone (defaults to the Craft *system* timezone).
 * DST-safe: uses DateTimeImmutable arithmetic instead of strtotime.
 *
 * The optional $tz / $today parameters let unit tests pin a specific zone or reference date
 * without booting Craft.
 */
class AnalyticsTime
{
    /**
     * Span thresholds, in whole days, for picking a series granularity. Mirrors what the CP
     * date picker used to decide client-side before ranges moved server-side.
     */
    private const HOURLY_MAX_DAYS = 2;
    private const DAILY_MAX_DAYS = 365;

    /**
     * A rolling day, in ms. Deliberately a fixed 24×3600s rather than "one calendar day ago":
     * the "Last 24 hours" window means 24 hours even across a DST transition, where the local
     * clock reads the same time 23 or 25 hours earlier.
     */
    private const ROLLING_DAY_MS = 24 * 3600 * 1000;

    /**
     * The system timezone — the one every stored day key is bucketed in.
     *
     * Deliberately *not* Craft::$app->getTimeZone(): inside a CP request that resolves to the
     * signed-in user's personal timezone preference, so the same helper would key mirror rows
     * by one admin's zone and read them back in another's — or in the system zone again from a
     * console/queue run, which has no user at all. Day keys have to be stable regardless of who
     * triggered the work, so this follows Craft's own fallback order for the system zone while
     * ignoring the per-user preference.
     */
    public static function appTimeZone(): \DateTimeZone
    {
        $tz = Craft::$app->getConfig()->getGeneral()->timezone
            ?? Craft::$app->getProjectConfig()->get('system.timeZone');
        $tz = \is_string($tz) ? App::parseEnv($tz) : null;

        return new \DateTimeZone(\is_string($tz) && $tz !== '' ? $tz : 'UTC');
    }

    /**
     * Returns Y-m-d for a given offset in days from today, in the given timezone.
     */
    public static function dateOffset(int $daysAgo, ?\DateTimeZone $tz = null): string
    {
        $tz = $tz ?? self::appTimeZone();
        return (new \DateTimeImmutable('today', $tz))
            ->modify("-{$daysAgo} days")
            ->format('Y-m-d');
    }

    /**
     * Returns [startMs, endMs] for a Y-m-d date in the given timezone.
     * Start is local midnight; end is one second before the next local midnight, so DST
     * spring-forward / fall-back days are bounded correctly.
     *
     * @return array{0:int,1:int}
     */
    public static function dayBounds(string $dateStr, ?\DateTimeZone $tz = null): array
    {
        $tz = $tz ?? self::appTimeZone();
        $start = new \DateTimeImmutable($dateStr . ' 00:00:00', $tz);
        $end = $start->modify('+1 day')->modify('-1 second');
        return [$start->getTimestamp() * 1000, $end->getTimestamp() * 1000];
    }

    /**
     * Resolves a CP date-range preset to the window it denotes, plus the series granularity
     * that window should be charted at.
     *
     * These boundaries are computed here rather than in the browser on purpose. Deriving them
     * client-side from new Date() made every preset depend on the viewer's own clock, so a CP
     * session in another zone asked for a shifted window and then read the wrong day keys back
     * out of the mirror, which is bucketed in {@see self::appTimeZone()}.
     *
     * @return array{startAt:int,endAt:int,unit:string,compareStartAt:int}|null null if $preset is not a known preset
     */
    public static function presetRange(string $preset, ?\DateTimeZone $tz = null, ?\DateTimeImmutable $now = null): ?array
    {
        $tz = $tz ?? self::appTimeZone();
        $now = $now !== null ? $now->setTimezone($tz) : new \DateTimeImmutable('now', $tz);
        $today = $now->setTime(0, 0);

        // The one rolling window: a true 24-hour span ending now. It used to select the pair of
        // calendar days yesterday-midnight..tonight, which is 24 to 48 hours wide depending on
        // the time of day — at noon, "Last 24 hours" showed 36. Being the only preset that is
        // not day-aligned, it is also the reason the mirror has to clamp partial edge days
        // ({@see \szenario\craftobservatory\services\StatsReport::getRangeBreakdowns()}).
        if ($preset === '24h') {
            $endAt = $now->getTimestamp() * 1000;

            return [
                'startAt' => $endAt - self::ROLLING_DAY_MS,
                'endAt' => $endAt,
                'unit' => 'hour',
                'compareStartAt' => $endAt - 2 * self::ROLLING_DAY_MS,
            ];
        }

        // Month arithmetic runs from the 1st. Subtracting months from e.g. the 31st overflows
        // into the *following* month in PHP (Aug 31 minus 6 months lands on Mar 3), which would
        // put the window on the wrong month entirely.
        $monthStart = $today->modify('first day of this month');
        $weekStart = $today->modify('monday this week');

        $yearStart = $today->setDate((int) $today->format('Y'), 1, 1);

        // The fourth element is where the *previous* period starts. It is stepped back by one
        // whole period in calendar units, not by the elapsed millisecond count, so the
        // comparison lands at the same phase: "this week" on a Wednesday compares against the
        // previous Mon–Wed, not against the Fri–Sun immediately before it. Deriving it here
        // keeps it anchored to the same weekStart/monthStart the window itself uses — a second
        // preset list elsewhere is exactly how these drift apart.
        $range = match ($preset) {
            'today' => [$today, $today, 'hour', $today->modify('-1 day')],
            'this_week' => [$weekStart, $weekStart->modify('+6 days'), 'day', $weekStart->modify('-7 days')],
            '7d' => [$today->modify('-6 days'), $today, 'day', $today->modify('-13 days')],
            'this_month' => [$monthStart, $monthStart->modify('last day of this month'), 'day', $monthStart->modify('-1 month')],
            '30d' => [$today->modify('-29 days'), $today, 'day', $today->modify('-59 days')],
            '90d' => [$today->modify('-89 days'), $today, 'day', $today->modify('-179 days')],
            'this_year' => [$yearStart, $today, 'month', $yearStart->modify('-1 year')],
            '6m' => [$monthStart->modify('-5 months'), $today, 'month', $monthStart->modify('-11 months')],
            '12m' => [$monthStart->modify('-11 months'), $today, 'month', $monthStart->modify('-23 months')],
            default => null,
        };

        if ($range === null) {
            return null;
        }

        [$start, $end, $unit, $compareStart] = $range;

        return self::window($start, $end, $unit, $tz, $compareStart);
    }

    /**
     * Resolves an explicit Y-m-d..Y-m-d custom range, in the given timezone.
     *
     * Both dates arrive straight off a query string, so they are validated rather than coerced:
     * anything that is not a real calendar date is rejected outright.
     *
     * @return array{startAt:int,endAt:int,unit:string,compareStartAt:int}|null null if either date is malformed
     */
    public static function customRange(string $startDate, string $endDate, ?\DateTimeZone $tz = null): ?array
    {
        $tz = $tz ?? self::appTimeZone();
        $start = self::parseDate($startDate, $tz);
        $end = self::parseDate($endDate, $tz);

        if ($start === null || $end === null) {
            return null;
        }

        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        // +1 because both endpoints are inclusive: a single date is a one-day range.
        $days = (int) $start->diff($end)->days + 1;
        $unit = match (true) {
            $days <= self::HOURLY_MAX_DAYS => 'hour',
            $days <= self::DAILY_MAX_DAYS => 'day',
            default => 'month',
        };

        return self::window($start, $end, $unit, $tz, $start->modify("-{$days} days"));
    }

    /**
     * @return array{startAt:int,endAt:int,unit:string,compareStartAt:int}
     */
    private static function window(
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        string $unit,
        \DateTimeZone $tz,
        \DateTimeImmutable $compareStart,
    ): array {
        [$startAt] = self::dayBounds($start->format('Y-m-d'), $tz);
        [, $endAt] = self::dayBounds($end->format('Y-m-d'), $tz);
        [$compareStartAt] = self::dayBounds($compareStart->format('Y-m-d'), $tz);

        return [
            'startAt' => $startAt,
            'endAt' => $endAt,
            'unit' => $unit,
            'compareStartAt' => $compareStartAt,
        ];
    }

    /**
     * Strict Y-m-d parse. Rejects both wrong shapes and impossible dates like 2026-02-31, which
     * createFromFormat would otherwise silently roll forward into March.
     */
    private static function parseDate(string $date, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}

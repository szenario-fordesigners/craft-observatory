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
     * @return array{startAt:int,endAt:int,unit:string}|null null if $preset is not a known preset
     */
    public static function presetRange(string $preset, ?\DateTimeZone $tz = null, ?\DateTimeImmutable $today = null): ?array
    {
        $tz = $tz ?? self::appTimeZone();
        $today = $today !== null
            ? $today->setTimezone($tz)->setTime(0, 0)
            : new \DateTimeImmutable('today', $tz);

        // Month arithmetic runs from the 1st. Subtracting months from e.g. the 31st overflows
        // into the *following* month in PHP (Aug 31 minus 6 months lands on Mar 3), which would
        // put the window on the wrong month entirely.
        $monthStart = $today->modify('first day of this month');
        $weekStart = $today->modify('monday this week');

        $range = match ($preset) {
            'today' => [$today, $today, 'hour'],
            // Spans two calendar days, matching the window this preset has always produced —
            // its "Last 24 hours" label has been inaccurate for as long as it has existed.
            '24h' => [$today->modify('-1 day'), $today, 'hour'],
            'this_week' => [$weekStart, $weekStart->modify('+6 days'), 'day'],
            '7d' => [$today->modify('-6 days'), $today, 'day'],
            'this_month' => [$monthStart, $monthStart->modify('last day of this month'), 'day'],
            '30d' => [$today->modify('-29 days'), $today, 'day'],
            '90d' => [$today->modify('-89 days'), $today, 'day'],
            'this_year' => [$today->setDate((int) $today->format('Y'), 1, 1), $today, 'month'],
            '6m' => [$monthStart->modify('-5 months'), $today, 'month'],
            '12m' => [$monthStart->modify('-11 months'), $today, 'month'],
            default => null,
        };

        if ($range === null) {
            return null;
        }

        [$start, $end, $unit] = $range;

        return self::window($start, $end, $unit, $tz);
    }

    /**
     * Resolves an explicit Y-m-d..Y-m-d custom range, in the given timezone.
     *
     * Both dates arrive straight off a query string, so they are validated rather than coerced:
     * anything that is not a real calendar date is rejected outright.
     *
     * @return array{startAt:int,endAt:int,unit:string}|null null if either date is malformed
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

        return self::window($start, $end, $unit, $tz);
    }

    /**
     * @return array{startAt:int,endAt:int,unit:string}
     */
    private static function window(\DateTimeImmutable $start, \DateTimeImmutable $end, string $unit, \DateTimeZone $tz): array
    {
        [$startAt] = self::dayBounds($start->format('Y-m-d'), $tz);
        [, $endAt] = self::dayBounds($end->format('Y-m-d'), $tz);

        return ['startAt' => $startAt, 'endAt' => $endAt, 'unit' => $unit];
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

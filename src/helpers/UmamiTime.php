<?php

namespace szenario\craftumamiis\helpers;

use Craft;

/**
 * Date helpers tied to a timezone (defaults to the Craft application timezone).
 * DST-safe: uses DateTimeImmutable arithmetic instead of strtotime.
 *
 * The optional $tz parameter lets unit tests pin a specific zone without booting Craft.
 */
class UmamiTime
{
    public static function appTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone(Craft::$app->getTimeZone());
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
}

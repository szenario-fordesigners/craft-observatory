<?php

namespace szenario\craftumamiis\helpers;

use Craft;

/**
 * Date helpers tied to the Craft application timezone.
 * DST-safe: uses DateTimeImmutable arithmetic instead of strtotime.
 */
class UmamiTime
{
    public static function appTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone(Craft::$app->getTimeZone());
    }

    /**
     * Returns Y-m-d for a given offset in days from today, in the app timezone.
     */
    public static function dateOffset(int $daysAgo): string
    {
        return (new \DateTimeImmutable('today', self::appTimeZone()))
            ->modify("-{$daysAgo} days")
            ->format('Y-m-d');
    }

    /**
     * Returns [startMs, endMs] for a Y-m-d date in the app timezone.
     * Start is local midnight; end is one second before the next local midnight, so DST
     * spring-forward / fall-back days are bounded correctly.
     *
     * @return array{0:int,1:int}
     */
    public static function dayBounds(string $dateStr): array
    {
        $start = new \DateTimeImmutable($dateStr . ' 00:00:00', self::appTimeZone());
        $end = $start->modify('+1 day')->modify('-1 second');
        return [$start->getTimestamp() * 1000, $end->getTimestamp() * 1000];
    }
}

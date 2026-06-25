<?php

namespace szenario\craftobservatory\services;

use craft\base\Component;
use szenario\craftobservatory\helpers\AnalyticsTime;
use szenario\craftobservatory\records\DailyStats;
use szenario\craftobservatory\records\HourlyStats;
use szenario\craftobservatory\Observatory;

/**
 * Builds the daily-stats report consumed by the CP page and dashboard widget.
 *
 * Historical days come from the local DB; missing rows surface as 'Queued' placeholders
 * and are filled by SyncCoordinator's background job. Today is fetched live through
 * the configured analytics source because it is still accumulating.
 */
class StatsReport extends Component
{
    /**
     * Compact summary used by the dashboard widget: 7-day visitor totals with prior-period
     * comparison, daily visitor series, and top country/referrer/browser.
     *
     * Lean on the analytics response cache (5-min TTL) — endAt is bucketed to a 5-min
     * boundary so cache keys stabilize within the window.
     *
     * @return array{
     *     totalVisitors:int,
     *     priorVisitors:int,
     *     deltaPercent:int,
     *     deltaDirection:int,
     *     daily:array<int,array{date:string,visitors:int}>,
     *     top:array{
     *         country:?array{x:string,y:int},
     *         referrer:?array{x:string,y:int},
     *         browser:?array{x:string,y:int}
     *     }
     * }
     */
    public function getWidgetSummary(): array
    {
        $empty = [
            'totalVisitors' => 0,
            'priorVisitors' => 0,
            'deltaPercent' => 0,
            'deltaDirection' => 0,
            'daily' => [],
            'top' => ['country' => null, 'referrer' => null, 'browser' => null],
        ];

        $websiteId = Observatory::getInstance()->analytics->getStorageKey();

        if (empty($websiteId)) {
            return $empty;
        }

        $bucketSec = 300;
        $nowBucketed = (int) (floor(time() / $bucketSec) * $bucketSec * 1000);

        // Last 7 days: midnight 6 days ago → now (bucketed).
        [$weekStart, ] = AnalyticsTime::dayBounds(AnalyticsTime::dateOffset(6));

        // Prior 7 days: 14 days ago → 7 days ago (inclusive). Fixed window — cached effectively forever.
        [$priorStart, ] = AnalyticsTime::dayBounds(AnalyticsTime::dateOffset(13));
        [, $priorEnd] = AnalyticsTime::dayBounds(AnalyticsTime::dateOffset(7));

        $analytics = Observatory::getInstance()->analytics;

        $weekStats = $analytics->getTotals($weekStart, $nowBucketed, $bucketSec) ?? [];
        $priorStats = $analytics->getTotals($priorStart, $priorEnd, $bucketSec) ?? [];
        $metrics = $analytics->getBreakdowns(
            $weekStart,
            $nowBucketed,
            ['country', 'referrer', 'browser'],
            $bucketSec,
        );

        $totalVisitors = (int) ($weekStats['visitors'] ?? 0);
        $priorVisitors = (int) ($priorStats['visitors'] ?? 0);
        [$deltaPercent, $deltaDirection] = $this->computeDelta($totalVisitors, $priorVisitors);

        $syncing = false;
        $daily = array_reverse(array_map(
            static function (array $row) use (&$syncing): array {
                $queued = $row['source'] === 'Queued';
                if ($queued) {
                    $syncing = true;
                }
                return ['date' => $row['date'], 'visitors' => (int) $row['visitors'], 'queued' => $queued];
            },
            $this->getDailyStatsReport(7),
        ));

        return [
            'totalVisitors' => $totalVisitors,
            'priorVisitors' => $priorVisitors,
            'deltaPercent' => $deltaPercent,
            'deltaDirection' => $deltaDirection,
            'daily' => $daily,
            'top' => [
                'country' => $this->topMetric($metrics['country'] ?? []),
                'referrer' => $this->topMetric($metrics['referrer'] ?? []),
                'browser' => $this->topMetric($metrics['browser'] ?? []),
            ],
            // Full country breakdown — same fetched data the top-country derives from,
            // surfaced for the inline world map in the visitors widget.
            'countries' => $metrics['country'] ?? [],
            '_syncing' => $syncing,
        ];
    }

    /**
     * Compact summary for the usage widget: 7-day total views, average visit duration,
     * and a daily views series for the bar chart.
     *
     * Total views and duration come from a single bucketed /stats call (5-min cache);
     * the daily series reuses the DB-backed report, surfacing a `_syncing` flag when
     * historical days are still queued.
     *
     * @return array{
     *     totalViews:int,
     *     priorViews:int,
     *     deltaPercent:int,
     *     deltaDirection:int,
     *     avgDuration:int,
     *     daily:array<int,array{date:string,views:int,queued:bool}>,
     *     _syncing:bool
     * }
     */
    public function getUsageSummary(): array
    {
        $empty = [
            'totalViews' => 0,
            'priorViews' => 0,
            'deltaPercent' => 0,
            'deltaDirection' => 0,
            'avgDuration' => 0,
            'daily' => [],
            '_syncing' => false,
        ];

        $websiteId = Observatory::getInstance()->analytics->getStorageKey();

        if (empty($websiteId)) {
            return $empty;
        }

        $bucketSec = 300;
        $nowBucketed = (int) (floor(time() / $bucketSec) * $bucketSec * 1000);

        // Last 7 days: midnight 6 days ago → now (bucketed).
        [$weekStart, ] = AnalyticsTime::dayBounds(AnalyticsTime::dateOffset(6));

        // Prior 7 days: 14 days ago → 7 days ago. Fixed window — cached effectively forever.
        [$priorStart, ] = AnalyticsTime::dayBounds(AnalyticsTime::dateOffset(13));
        [, $priorEnd] = AnalyticsTime::dayBounds(AnalyticsTime::dateOffset(7));

        $analytics = Observatory::getInstance()->analytics;
        $weekStats = $analytics->getTotals($weekStart, $nowBucketed, $bucketSec) ?? [];
        $priorStats = $analytics->getTotals($priorStart, $priorEnd, $bucketSec) ?? [];

        $totalViews = (int) ($weekStats['pageviews'] ?? 0);
        $priorViews = (int) ($priorStats['pageviews'] ?? 0);
        [$deltaPercent, $deltaDirection] = $this->computeDelta($totalViews, $priorViews);

        $visits = (int) ($weekStats['visits'] ?? 0);
        $sessionDurationSeconds = (int) ($weekStats['sessionDurationSeconds'] ?? 0);
        // Average session duration in whole seconds; the client formats it as m:ss.
        $avgDuration = $visits > 0 ? (int) round($sessionDurationSeconds / $visits) : 0;

        $syncing = false;
        $daily = array_reverse(array_map(
            static function (array $row) use (&$syncing): array {
                $queued = $row['source'] === 'Queued';
                if ($queued) {
                    $syncing = true;
                }
                return ['date' => $row['date'], 'views' => (int) $row['pageviews'], 'queued' => $queued];
            },
            $this->getDailyStatsReport(7),
        ));

        return [
            'totalViews' => $totalViews,
            'priorViews' => $priorViews,
            'deltaPercent' => $deltaPercent,
            'deltaDirection' => $deltaDirection,
            'avgDuration' => $avgDuration,
            'daily' => $daily,
            '_syncing' => $syncing,
        ];
    }

    /**
     * @param array<mixed> $metric
     * @return array{x:string,y:int}|null
     */
    private function topMetric(array $metric): ?array
    {
        $top = $metric[0] ?? null;
        if (!\is_array($top) || !isset($top['x'])) {
            return null;
        }

        return ['x' => (string) $top['x'], 'y' => (int) ($top['y'] ?? 0)];
    }

    /**
     * @return array{0:int,1:int} [percent, direction (-1|0|1)]
     */
    private function computeDelta(int $current, int $prior): array
    {
        if ($prior === 0) {
            return [$current > 0 ? 100 : 0, $current > 0 ? 1 : 0];
        }

        $pct = (int) round((($current - $prior) / $prior) * 100);
        $dir = $current === $prior ? 0 : ($current > $prior ? 1 : -1);
        return [$pct, $dir];
    }

    /**
     * Aggregates hourly visitor data from the local DB into a 7×24 heatmap grid.
     *
     * Returns one cell per observed (weekday, hour) combination over the lookback window.
     * weekday: 0=Monday … 6=Sunday. Visitors value is the average across all matching days.
     *
     * @return array{cells:array<int,array{weekday:int,hour:int,visitors:float}>,maxVisitors:float,daysWithData:int}
     */
    public function getHeatmapData(int $lookbackDays = 90): array
    {
        $websiteId = Observatory::getInstance()->analytics->getStorageKey();

        $empty = ['cells' => [], 'maxVisitors' => 0.0, 'daysWithData' => 0];

        if (empty($websiteId)) {
            return $empty;
        }

        $startDate = AnalyticsTime::dateOffset($lookbackDays);

        /** @var HourlyStats[] $rows */
        $rows = HourlyStats::find()
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $startDate])
            ->all();

        if (empty($rows)) {
            return $empty;
        }

        // Aggregate: sum visitors and count occurrences per (weekday, hour) cell.
        $aggregates = [];
        $uniqueDates = [];
        foreach ($rows as $row) {
            $dt = new \DateTimeImmutable($row->date);
            // DateTimeImmutable::format('N') → 1=Mon … 7=Sun; subtract 1 for 0-based Mon=0.
            $weekday = (int) $dt->format('N') - 1;
            $hour = (int) $row->hour;
            $key = "{$weekday}:{$hour}";

            if (!isset($aggregates[$key])) {
                $aggregates[$key] = ['weekday' => $weekday, 'hour' => $hour, 'sum' => 0, 'count' => 0];
            }
            $aggregates[$key]['sum'] += $row->visitors;
            $aggregates[$key]['count']++;
            $uniqueDates[$row->date] = true;
        }

        $cells = [];
        $maxVisitors = 0.0;
        foreach ($aggregates as $agg) {
            $avg = $agg['count'] > 0 ? round($agg['sum'] / $agg['count'], 1) : 0.0;
            $cells[] = ['weekday' => $agg['weekday'], 'hour' => $agg['hour'], 'visitors' => $avg];
            if ($avg > $maxVisitors) {
                $maxVisitors = $avg;
            }
        }

        return [
            'cells' => $cells,
            'maxVisitors' => $maxVisitors,
            'daysWithData' => \count($uniqueDates),
        ];
    }

    /**
     * @param int $days Number of days to include (including today).
     * @return array<int,array{date:string,pageviews:int,visitors:int,visits:int,sessionDurationSeconds:int,metrics:array,source:string}>
     */
    public function getDailyStatsReport(int $days = 30): array
    {
        $websiteId = Observatory::getInstance()->analytics->getStorageKey();

        if (empty($websiteId)) {
            return [];
        }

        $report = [];
        $todayStr = AnalyticsTime::dateOffset(0);

        // Pre-fetch all available DB records for the requested timeframe
        $startDateStr = AnalyticsTime::dateOffset($days - 1);

        $dbRecords = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $startDateStr])
            ->indexBy('date')
            ->all();

        // Round endAt down to a 60s bucket so the today-call shares a cache key across renders.
        $todayBucketSec = 60;
        $todayEndAt = (int) (floor(time() / $todayBucketSec) * $todayBucketSec * 1000);

        for ($i = 0; $i < $days; $i++) {
            $dateStr = AnalyticsTime::dateOffset($i);
            $isToday = ($dateStr === $todayStr);

            if (!$isToday && isset($dbRecords[$dateStr])) {
                $record = $dbRecords[$dateStr];
                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => $record->pageviews,
                    'visitors' => $record->visitors,
                    'visits' => $record->visits,
                    'sessionDurationSeconds' => $record->sessionDurationSeconds,
                    'metrics' => $record->metrics ? json_decode($record->metrics, true) : [],
                    'source' => 'DB',
                ];
            } elseif ($isToday) {
                [$startAt, ] = AnalyticsTime::dayBounds($dateStr);
                $stats = Observatory::getInstance()->analytics->getTotals($startAt, $todayEndAt, $todayBucketSec);

                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => (int) ($stats['pageviews'] ?? 0),
                    'visitors' => (int) ($stats['visitors'] ?? 0),
                    'visits' => (int) ($stats['visits'] ?? 0),
                    'sessionDurationSeconds' => (int) ($stats['sessionDurationSeconds'] ?? 0),
                    'metrics' => [],
                    'source' => 'API',
                ];
            } else {
                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => 0,
                    'visitors' => 0,
                    'visits' => 0,
                    'sessionDurationSeconds' => 0,
                    'metrics' => [],
                    'source' => 'Queued',
                ];
            }
        }

        return $report;
    }

    /**
     * Mirror-aware multi-breakdown for a date range.
     *
     * Closed days (before today) are summed from the local mirror's per-day
     * `metrics` JSON; today is fetched live from the provider and merged on top; each
     * dimension is then re-ranked to a top-100 {x,y} list. This collapses what used to
     * be one provider query per dimension over the whole range into a single live query
     * for today plus one local DB read — and stays cheap regardless of range length.
     *
     * Only {@see Observatory::MIRRORED_METRIC_TYPES} are mirror-served; any other type
     * falls through to a fully live breakdown over the range. The result keys always
     * match the requested types.
     *
     * Correctness notes:
     *  - Counts are summed per label across days. Pageview/session counts sum correctly,
     *    which is why only those dimensions live in MIRRORED_METRIC_TYPES — unique-visitor
     *    style metrics must not be served here (daily uniques don't sum to a range unique).
     *  - Each day contributes only its stored top-100, so head rankings are accurate while
     *    the deep tail is approximate.
     *  - Days are the unit of granularity: the start/end timestamps are floored to local
     *    dates, so a sub-day window still counts whole days. The CP UI only ever emits
     *    day-aligned ranges, so this matches what callers ask for.
     *
     * @param string[] $types
     * @return array<string,array<int,array{x:string,y:int}>>
     */
    public function getRangeBreakdowns(array $types, int $startAt, int $endAt, int $cacheDuration = 300): array
    {
        $types = array_values(array_unique(array_filter(array_map('trim', $types))));
        if (empty($types)) {
            return [];
        }

        $analytics = Observatory::getInstance()->analytics;
        $websiteId = $analytics->getStorageKey();
        if (empty($websiteId)) {
            return array_fill_keys($types, []);
        }

        $results = array_fill_keys($types, []);

        // Types we don't mirror go straight to the source over the full range.
        $mirrored = array_values(array_intersect($types, Observatory::MIRRORED_METRIC_TYPES));
        $liveOnly = array_values(array_diff($types, $mirrored));
        if (!empty($liveOnly)) {
            $results = array_merge($results, $analytics->getBreakdowns($startAt, $endAt, $liveOnly, $cacheDuration));
        }
        if (empty($mirrored)) {
            return $results;
        }

        $tz = AnalyticsTime::appTimeZone();
        $todayStr = AnalyticsTime::dateOffset(0);
        $startDateStr = (new \DateTimeImmutable('@' . intdiv($startAt, 1000)))->setTimezone($tz)->format('Y-m-d');
        $endDateStr = (new \DateTimeImmutable('@' . intdiv($endAt, 1000)))->setTimezone($tz)->format('Y-m-d');

        // acc[type][label] = summed count across closed days (+ today, merged below).
        $acc = array_fill_keys($mirrored, []);

        /** @var DailyStats[] $records */
        $records = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $startDateStr])
            ->andWhere(['<=', 'date', $endDateStr])
            ->andWhere(['<', 'date', $todayStr])
            ->all();

        foreach ($records as $record) {
            $metrics = $record->metrics ? json_decode($record->metrics, true) : [];
            if (!\is_array($metrics)) {
                continue;
            }
            $this->_accumulateBreakdowns($acc, $mirrored, $metrics);
        }

        // Merge today live, but only when the range actually reaches today.
        if ($endDateStr >= $todayStr) {
            [$todayStart] = AnalyticsTime::dayBounds($todayStr);
            $todayStart = max($todayStart, $startAt);

            $todayBucketSec = 60;
            $todayEnd = min($endAt, (int) (floor(time() / $todayBucketSec) * $todayBucketSec * 1000));

            if ($todayEnd > $todayStart) {
                $today = $analytics->getBreakdowns($todayStart, $todayEnd, $mirrored, $todayBucketSec);
                $this->_accumulateBreakdowns($acc, $mirrored, $today);
            }
        }

        foreach ($mirrored as $type) {
            $pairs = $acc[$type];
            arsort($pairs);
            $list = [];
            foreach (\array_slice($pairs, 0, 100, true) as $label => $count) {
                $list[] = ['x' => (string) $label, 'y' => (int) $count];
            }
            $results[$type] = $list;
        }

        return $results;
    }

    /**
     * Mirror-aware single breakdown. Thin wrapper over {@see self::getRangeBreakdowns()}.
     *
     * @return array<int,array{x:string,y:int}>
     */
    public function getRangeBreakdown(string $type, int $startAt, int $endAt, int $cacheDuration = 300): array
    {
        return $this->getRangeBreakdowns([$type], $startAt, $endAt, $cacheDuration)[$type] ?? [];
    }

    /**
     * Mirror-aware pageview/session time series for a date range.
     *
     * Day- and month-granularity ranges are served from `observatory_daily_stats`:
     * closed days are bucketed (per day, or summed per calendar month) and today is
     * folded in live. This turns a full-range provider scan into one DB read plus one
     * today query, regardless of range length. Pageview and session *counts* sum
     * correctly across days, which is why both series can be aggregated this way.
     *
     * Hour granularity stays live — those ranges are only a day or two, so the live
     * query is already cheap and per-hour rows aren't kept in this table.
     *
     * Buckets are emitted as local-midnight datetime strings (day → that day, month →
     * the 1st) so they share the browser's timezone with the live today point;
     * `LineChart` parses both via `new Date()`. Days without a synced row are simply
     * omitted, exactly as the live GROUP BY omits zero-traffic buckets.
     *
     * @return array{pageviews:array<int,array{x:string,t:string,y:int}>,sessions:array<int,array{x:string,t:string,y:int}>}|null
     */
    public function getRangePageviews(int $startAt, int $endAt, string $unit = 'day'): ?array
    {
        $analytics = Observatory::getInstance()->analytics;

        // Only day/month granularity is mirror-served; hour (and anything else) stays live.
        if ($unit !== 'day' && $unit !== 'month') {
            return $analytics->getPageviews($startAt, $endAt, $unit);
        }

        $websiteId = $analytics->getStorageKey();
        if (empty($websiteId)) {
            return $analytics->getPageviews($startAt, $endAt, $unit);
        }

        $tz = AnalyticsTime::appTimeZone();
        $todayStr = AnalyticsTime::dateOffset(0);
        $startDateStr = (new \DateTimeImmutable('@' . intdiv($startAt, 1000)))->setTimezone($tz)->format('Y-m-d');
        $endDateStr = (new \DateTimeImmutable('@' . intdiv($endAt, 1000)))->setTimezone($tz)->format('Y-m-d');

        // Bucket closed days into pv[key]/ss[key]. Keys sort chronologically as strings
        // ('Y-m-d' or 'Y-m'), so ksort gives the series in order.
        $pv = [];
        $ss = [];
        $addToBucket = static function (string $dateStr, int $pageviews, int $sessions) use (&$pv, &$ss, $unit): void {
            if ($unit === 'month') {
                $key = substr($dateStr, 0, 7);
                $ts = $key . '-01 00:00:00';
            } else {
                $key = $dateStr;
                $ts = $dateStr . ' 00:00:00';
            }
            if (!isset($pv[$key])) {
                $pv[$key] = ['x' => $ts, 't' => $ts, 'y' => 0];
                $ss[$key] = ['x' => $ts, 't' => $ts, 'y' => 0];
            }
            $pv[$key]['y'] += $pageviews;
            $ss[$key]['y'] += $sessions;
        };

        /** @var DailyStats[] $records */
        $records = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $startDateStr])
            ->andWhere(['<=', 'date', $endDateStr])
            ->andWhere(['<', 'date', $todayStr])
            ->all();

        foreach ($records as $record) {
            $addToBucket($record->date, (int) $record->pageviews, (int) $record->visits);
        }

        // Fold today live into its bucket (its own day, or the current month).
        if ($endDateStr >= $todayStr) {
            [$todayStart] = AnalyticsTime::dayBounds($todayStr);
            $todayStart = max($todayStart, $startAt);

            if ($endAt > $todayStart) {
                $today = $analytics->getPageviews($todayStart, $endAt, 'day');
                $todayPv = array_sum(array_map(static fn($p) => (int) ($p['y'] ?? 0), $today['pageviews'] ?? []));
                $todaySs = array_sum(array_map(static fn($p) => (int) ($p['y'] ?? 0), $today['sessions'] ?? []));
                if ($todayPv > 0 || $todaySs > 0) {
                    $addToBucket($todayStr, $todayPv, $todaySs);
                }
            }
        }

        ksort($pv);
        ksort($ss);

        return ['pageviews' => array_values($pv), 'sessions' => array_values($ss)];
    }

    /**
     * Folds a `{type: [{x,y}, …]}` breakdown map into the running accumulator.
     *
     * @param array<string,array<string,int>> $acc Modified in place: acc[type][label] += y.
     * @param string[] $types
     * @param array<mixed> $breakdowns
     */
    private function _accumulateBreakdowns(array &$acc, array $types, array $breakdowns): void
    {
        foreach ($types as $type) {
            foreach ($breakdowns[$type] ?? [] as $row) {
                if (!\is_array($row) || !isset($row['x'])) {
                    continue;
                }
                $label = (string) $row['x'];
                if ($label === '') {
                    continue;
                }
                $acc[$type][$label] = ($acc[$type][$label] ?? 0) + (int) ($row['y'] ?? 0);
            }
        }
    }
}

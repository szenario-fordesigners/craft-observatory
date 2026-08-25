<?php

namespace szenario\craftobservatory\services;

use craft\base\Component;
use szenario\craftobservatory\helpers\AnalyticsTime;
use szenario\craftobservatory\Observatory;
use szenario\craftobservatory\records\DailyStats;
use szenario\craftobservatory\records\HourlyStats;
use szenario\craftobservatory\records\SyncState;

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
     * weekday: 0=Monday … 6=Sunday. The value is the mean visitors across every *observed*
     * occurrence of that weekday — not across the occurrences that happened to have traffic.
     *
     * That distinction is the whole correctness of this widget. The mirror stores no row for an
     * hour with no visitors, so dividing by the number of rows found would divide by "Mondays
     * where 09:00 was busy" instead of "Mondays observed": a slot busy on one Monday out of
     * thirteen would render exactly as hot as one busy every Monday, which inverts what a
     * traffic-pattern heatmap is for.
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

        $observedPerWeekday = $this->_observedDaysPerWeekday($websiteId, $startDate, $rows);

        // Sum visitors per (weekday, hour) cell. The divisor comes from the observed-day tally
        // above, never from how many rows landed in the cell.
        $aggregates = [];
        foreach ($rows as $row) {
            $weekday = self::_weekdayOf($row->date);
            $hour = (int) $row->hour;
            $key = "{$weekday}:{$hour}";

            if (!isset($aggregates[$key])) {
                $aggregates[$key] = ['weekday' => $weekday, 'hour' => $hour, 'sum' => 0];
            }
            $aggregates[$key]['sum'] += $row->visitors;
        }

        $cells = [];
        $maxVisitors = 0.0;
        foreach ($aggregates as $agg) {
            $days = $observedPerWeekday[$agg['weekday']] ?? 0;
            if ($days < 1) {
                // Unreachable while the tally includes every date that produced a row, which it
                // does — belt and braces against a divide-by-zero if that ever stops holding.
                continue;
            }

            $avg = round($agg['sum'] / $days, 1);
            $cells[] = ['weekday' => $agg['weekday'], 'hour' => $agg['hour'], 'visitors' => $avg];
            if ($avg > $maxVisitors) {
                $maxVisitors = $avg;
            }
        }

        return [
            'cells' => $cells,
            'maxVisitors' => $maxVisitors,
            'daysWithData' => array_sum($observedPerWeekday),
        ];
    }

    /**
     * Counts, per weekday, how many days in the window were actually fetched.
     *
     * A day whose `hourly` facet is done was queried, so an hour with no row for it genuinely had
     * no visitors and must still weigh on that slot's mean. Dates that produced rows are unioned
     * in, so a mirror where rows exist without a matching done facet still yields a usable
     * divisor rather than dropping the cell.
     *
     * @param HourlyStats[] $rows
     * @return array<int,int> weekday (0=Mon … 6=Sun) => observed day count
     */
    private function _observedDaysPerWeekday(string $websiteId, string $startDate, array $rows): array
    {
        $fetched = SyncState::find()
            ->select(['date'])
            ->where([
                'websiteId' => $websiteId,
                'facet' => SyncCoordinator::FACET_HOURLY,
                'status' => SyncCoordinator::STATUS_DONE,
            ])
            ->andWhere(['>=', 'date', $startDate])
            ->column();

        $observed = [];
        foreach ($fetched as $date) {
            $observed[(string) $date] = true;
        }
        foreach ($rows as $row) {
            $observed[$row->date] = true;
        }

        return self::_weekdayTally(array_keys($observed));
    }

    /**
     * Tallies Y-m-d dates by weekday — the divisor each heatmap column is averaged over.
     *
     * @param string[] $dates
     * @return array<int,int> weekday (0=Mon … 6=Sun) => count
     */
    private static function _weekdayTally(array $dates): array
    {
        $perWeekday = array_fill(0, 7, 0);
        foreach ($dates as $date) {
            $perWeekday[self::_weekdayOf($date)]++;
        }

        return $perWeekday;
    }

    /**
     * Weekday of a Y-m-d date, 0=Monday … 6=Sunday.
     *
     * No timezone is needed: a date-only string is midnight in whatever zone it is read in, so
     * the calendar day — and therefore the weekday — is the same either way.
     */
    private static function _weekdayOf(string $date): int
    {
        return (int) (new \DateTimeImmutable($date))->format('N') - 1;
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
     *  - Days are the unit of granularity in the mirror, so a window that begins mid-day takes
     *    its leading partial day from the source live instead; counting that day's whole-day row
     *    would include the hours before the window started. The trailing edge is today, which is
     *    already served live over the clamped window.
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

        // A window starting mid-day (the rolling "24h" preset) cannot take its first day from the
        // mirror: these rows are whole-day totals with no hour to slice by, so counting that day
        // would silently add the hours before the window began. The mirror starts at the first
        // day the window covers in full, and the leading remainder is fetched live below.
        [$startDayStart] = AnalyticsTime::dayBounds($startDateStr, $tz);
        $hasLeadingPartialDay = $startAt > $startDayStart;
        $mirrorFromStr = $hasLeadingPartialDay
            ? (new \DateTimeImmutable($startDateStr . ' 00:00:00', $tz))->modify('+1 day')->format('Y-m-d')
            : $startDateStr;

        // acc[type][label] = summed count across closed days (+ partial edges, merged below).
        $acc = array_fill_keys($mirrored, []);

        /** @var DailyStats[] $records */
        $records = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $mirrorFromStr])
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

        // Merge the leading partial day live. Skipped when that day is today, which the branch
        // below already covers over the same clamped window.
        if ($hasLeadingPartialDay && $startDateStr < $todayStr) {
            [, $leadingEnd] = AnalyticsTime::dayBounds($startDateStr, $tz);
            $leadingEnd = min($leadingEnd, $endAt);

            if ($leadingEnd > $startAt) {
                $leading = $analytics->getBreakdowns($startAt, $leadingEnd, $mirrored, $cacheDuration);
                $this->_accumulateBreakdowns($acc, $mirrored, $leading);
            }
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
     * Hour, day, and month granularity are served from the mirror for closed days, with
     * today folded in live. This turns full-range provider scans into one DB read plus
     * one today query, regardless of range length. Pageview and session *counts* sum
     * correctly across days, which is why both series can be aggregated this way.
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

        // Only hour/day/month granularity is mirror-served; anything else stays live.
        if ($unit !== 'hour' && $unit !== 'day' && $unit !== 'month') {
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

        // Bucket closed rows into pv[key]/ss[key]. Keys sort chronologically as strings
        // ('Y-m-d H:00:00', 'Y-m-d', or 'Y-m'), so ksort gives the series in order.
        $pv = [];
        $ss = [];
        $addToBucket = static function (string $dateStr, int $pageviews, int $sessions, ?int $hour = null) use (&$pv, &$ss, $unit): void {
            if ($unit === 'month') {
                $key = substr($dateStr, 0, 7);
                $ts = $key . '-01 00:00:00';
            } elseif ($unit === 'hour') {
                $hour = max(0, min(23, $hour ?? 0));
                $key = sprintf('%s %02d', $dateStr, $hour);
                $ts = sprintf('%s %02d:00:00', $dateStr, $hour);
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

        if ($unit === 'hour') {
            /** @var HourlyStats[] $records */
            $records = HourlyStats::find()
                ->where(['websiteId' => $websiteId])
                ->andWhere(['>=', 'date', $startDateStr])
                ->andWhere(['<=', 'date', $endDateStr])
                ->andWhere(['<', 'date', $todayStr])
                ->all();

            foreach ($records as $record) {
                // Rows are selected by date, so the first and last day of a window that starts or
                // ends mid-day arrive whole. Unlike the daily mirror these carry an hour, so the
                // hours outside the window can simply be dropped rather than re-fetched live.
                if (!$this->_hourStartsWithin($record->date, (int) $record->hour, $startAt, $endAt, $tz)) {
                    continue;
                }

                $addToBucket($record->date, (int) $record->pageviews, (int) $record->visitors, (int) $record->hour);
            }
        } else {
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
        }

        // Fold today live into its bucket(s).
        if ($endDateStr >= $todayStr) {
            [$todayStart] = AnalyticsTime::dayBounds($todayStr);
            $todayStart = max($todayStart, $startAt);

            if ($endAt > $todayStart) {
                $today = $analytics->getPageviews($todayStart, $endAt, $unit === 'hour' ? 'hour' : 'day');
                if ($unit === 'hour') {
                    $todaySessions = $this->_seriesByHour($today['sessions'] ?? [], $tz);
                    foreach ($today['pageviews'] ?? [] as $point) {
                        $ts = (string) ($point['t'] ?? $point['x'] ?? '');
                        if ($ts === '') {
                            continue;
                        }
                        $hour = (int) (new \DateTimeImmutable($ts, $tz))->setTimezone($tz)->format('G');
                        $addToBucket($todayStr, (int) ($point['y'] ?? 0), $todaySessions[$hour] ?? 0, $hour);
                    }
                } else {
                    $todayPv = array_sum(array_map(static fn($p) => (int) ($p['y'] ?? 0), $today['pageviews'] ?? []));
                    $todaySs = array_sum(array_map(static fn($p) => (int) ($p['y'] ?? 0), $today['sessions'] ?? []));
                    if ($todayPv > 0 || $todaySs > 0) {
                        $addToBucket($todayStr, $todayPv, $todaySs);
                    }
                }
            }
        }

        ksort($pv);
        ksort($ss);

        return ['pageviews' => array_values($pv), 'sessions' => array_values($ss)];
    }

    /**
     * Whether a mirrored hourly row's bucket begins inside the window [$startAt, $endAt).
     *
     * The upper bound is exclusive: a bucket starting exactly at $endAt covers the hour *after*
     * the window. A bucket straddling either edge is dropped rather than counted whole — an hour
     * is the finest slice the mirror stores, so for a window that starts mid-hour this omits up
     * to 59 minutes from the leading bar instead of inventing up to 59 that fall outside it.
     * Totals and breakdowns don't inherit that rounding; they read the edges live at exact
     * instants.
     *
     * The local wall-clock hour is resolved through the timezone rather than added to midnight as
     * an offset, so hours on a DST transition day map to the instants they actually occurred at —
     * on those days the nth local hour is not n hours after local midnight.
     */
    private function _hourStartsWithin(string $dateStr, int $hour, int $startAt, int $endAt, \DateTimeZone $tz): bool
    {
        $hourStart = (new \DateTimeImmutable(sprintf('%s %02d:00:00', $dateStr, $hour), $tz))
            ->getTimestamp() * 1000;

        return $hourStart >= $startAt && $hourStart < $endAt;
    }

    /**
     * Indexes a live pageview/session series by local hour.
     *
     * The provider's timestamps arrive without an offset, so the constructor zone is load-bearing:
     * defaulting it would parse them in PHP's default zone, which Craft sets to the *viewing
     * user's* timezone on CP requests, and the resulting hour would then disagree with the
     * mirrored rows beside it — which are always bucketed in the system zone.
     *
     * @param array<int,array{x?:string,t?:string,y:int|string}> $series
     * @return array<int,int>
     */
    private function _seriesByHour(array $series, \DateTimeZone $tz): array
    {
        $indexed = [];
        foreach ($series as $point) {
            $ts = (string) ($point['t'] ?? $point['x'] ?? '');
            if ($ts === '') {
                continue;
            }
            $hour = (int) (new \DateTimeImmutable($ts, $tz))->setTimezone($tz)->format('G');
            $indexed[$hour] = (int) ($point['y'] ?? 0);
        }

        return $indexed;
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

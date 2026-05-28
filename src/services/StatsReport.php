<?php

namespace szenario\craftumamiis\services;

use craft\base\Component;
use craft\helpers\App;
use szenario\craftumamiis\helpers\UmamiTime;
use szenario\craftumamiis\records\DailyStats;
use szenario\craftumamiis\records\HourlyStats;
use szenario\craftumamiis\UmamiIs;

/**
 * Builds the daily-stats report consumed by the CP page and dashboard widget.
 *
 * Historical days come from the local DB; missing rows surface as 'Queued' placeholders
 * and are filled by SyncCoordinator's background job. Today is fetched live through
 * UmamiClient because it is still accumulating.
 */
class StatsReport extends Component
{
    /**
     * Compact summary used by the dashboard widget: 7-day visitor totals with prior-period
     * comparison, daily visitor series, and top country/referrer/browser.
     *
     * Lean on UmamiClient's response cache (5-min TTL) — endAt is bucketed to a 5-min
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

        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            return $empty;
        }

        $bucketSec = 300;
        $nowBucketed = (int) (floor(time() / $bucketSec) * $bucketSec * 1000);

        // Last 7 days: midnight 6 days ago → now (bucketed).
        [$weekStart, ] = UmamiTime::dayBounds(UmamiTime::dateOffset(6));

        // Prior 7 days: 14 days ago → 7 days ago (inclusive). Fixed window — cached effectively forever.
        [$priorStart, ] = UmamiTime::dayBounds(UmamiTime::dateOffset(13));
        [, $priorEnd] = UmamiTime::dayBounds(UmamiTime::dateOffset(7));

        $client = UmamiIs::getInstance()->client;

        $weekStats = $client->getStats($weekStart, $nowBucketed, $bucketSec) ?? [];
        $priorStats = $client->getStats($priorStart, $priorEnd, $bucketSec) ?? [];
        $metrics = $client->getMetricsBatch(
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

        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            return $empty;
        }

        $bucketSec = 300;
        $nowBucketed = (int) (floor(time() / $bucketSec) * $bucketSec * 1000);

        // Last 7 days: midnight 6 days ago → now (bucketed).
        [$weekStart, ] = UmamiTime::dayBounds(UmamiTime::dateOffset(6));

        // Prior 7 days: 14 days ago → 7 days ago. Fixed window — cached effectively forever.
        [$priorStart, ] = UmamiTime::dayBounds(UmamiTime::dateOffset(13));
        [, $priorEnd] = UmamiTime::dayBounds(UmamiTime::dateOffset(7));

        $client = UmamiIs::getInstance()->client;
        $weekStats = $client->getStats($weekStart, $nowBucketed, $bucketSec) ?? [];
        $priorStats = $client->getStats($priorStart, $priorEnd, $bucketSec) ?? [];

        $totalViews = (int) ($weekStats['pageviews'] ?? 0);
        $priorViews = (int) ($priorStats['pageviews'] ?? 0);
        [$deltaPercent, $deltaDirection] = $this->computeDelta($totalViews, $priorViews);

        $visits = (int) ($weekStats['visits'] ?? 0);
        $totaltime = (int) ($weekStats['totaltime'] ?? 0);
        // Average session duration in whole seconds; the client formats it as m:ss.
        $avgDuration = $visits > 0 ? (int) round($totaltime / $visits) : 0;

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
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        $empty = ['cells' => [], 'maxVisitors' => 0.0, 'daysWithData' => 0];

        if (empty($websiteId)) {
            return $empty;
        }

        $startDate = UmamiTime::dateOffset($lookbackDays);

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
     * @return array<int,array{date:string,pageviews:int,visitors:int,visits:int,bounces:int,totaltime:int,metrics:array,source:string}>
     */
    public function getDailyStatsReport(int $days = 30): array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            return [];
        }

        $report = [];
        $todayStr = UmamiTime::dateOffset(0);

        // Pre-fetch all available DB records for the requested timeframe
        $startDateStr = UmamiTime::dateOffset($days - 1);

        $dbRecords = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $startDateStr])
            ->indexBy('date')
            ->all();

        // Round endAt down to a 60s bucket so the today-call shares a cache key across renders.
        $todayBucketSec = 60;
        $todayEndAt = (int) (floor(time() / $todayBucketSec) * $todayBucketSec * 1000);

        for ($i = 0; $i < $days; $i++) {
            $dateStr = UmamiTime::dateOffset($i);
            $isToday = ($dateStr === $todayStr);

            if (!$isToday && isset($dbRecords[$dateStr])) {
                $record = $dbRecords[$dateStr];
                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => $record->pageviews,
                    'visitors' => $record->visitors,
                    'visits' => $record->visits,
                    'bounces' => $record->bounces,
                    'totaltime' => $record->totaltime,
                    'metrics' => $record->metrics ? json_decode($record->metrics, true) : [],
                    'source' => 'DB',
                ];
            } elseif ($isToday) {
                [$startAt, ] = UmamiTime::dayBounds($dateStr);
                $stats = UmamiIs::getInstance()->client->getStats($startAt, $todayEndAt, $todayBucketSec);

                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => (int) ($stats['pageviews'] ?? 0),
                    'visitors' => (int) ($stats['visitors'] ?? 0),
                    'visits' => (int) ($stats['visits'] ?? 0),
                    'bounces' => (int) ($stats['bounces'] ?? 0),
                    'totaltime' => (int) ($stats['totaltime'] ?? 0),
                    'metrics' => [],
                    'source' => 'API',
                ];
            } else {
                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => 0,
                    'visitors' => 0,
                    'visits' => 0,
                    'bounces' => 0,
                    'totaltime' => 0,
                    'metrics' => [],
                    'source' => 'Queued',
                ];
            }
        }

        return $report;
    }
}

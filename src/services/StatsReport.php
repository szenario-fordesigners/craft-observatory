<?php

namespace szenario\craftumamiis\services;

use craft\base\Component;
use craft\helpers\App;
use szenario\craftumamiis\helpers\UmamiTime;
use szenario\craftumamiis\records\DailyStats;
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

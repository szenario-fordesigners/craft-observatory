<?php

namespace szenario\craftumamiis\console\controllers;

use craft\console\Controller;
use szenario\craftumamiis\UmamiIs;
use yii\console\ExitCode;

/**
 * Sync controller for Umami analytics data
 */
class SyncController extends Controller
{
    /**
     * Syncs yesterday's stats from Umami into the local database.
     *
     * @return int
     */
    public function actionYesterday(): int
    {
        return $this->actionHistorical(1);
    }

    /**
     * Hand-pull the last X days from Umami and save to the local db. 
     * Defaults to last 30 days. Example: `craft umami-is/sync/historical 30`
     * 
     * @param int $days Number of days to pull
     * @return int
     */
    public function actionHistorical(int $days = 30): int
    {
        $this->stdout("Starting sync for the last {$days} days of Umami stats...\n");

        $analytics = UmamiIs::getInstance()->analytics;
        $successCount = 0;

        for ($i = 1; $i <= $days; $i++) {
            $dateStr = date('Y-m-d', strtotime("-{$i} days"));
            $startAt = strtotime($dateStr . ' midnight') * 1000;
            $endAt = strtotime($dateStr . ' 23:59:59') * 1000;

            $this->stdout("Fetching: {$dateStr}... ");

            $stats = $analytics->getStats($startAt, $endAt);

            if (empty($stats)) {
                $this->stderr("Failed to load basic stats.\n");
                continue;
            }

            // Fetch metrics
            $metricsTypes = ['url', 'title', 'referrer', 'os', 'browser', 'device', 'country', 'region', 'city'];
            $metrics = [];
            foreach ($metricsTypes as $type) {
                $typeData = $analytics->getMetrics($startAt, $endAt, $type);
                if (is_array($typeData)) {
                    $metrics[$type] = $typeData;
                }
            }

            $success = $analytics->syncDailyStats($dateStr, $stats, $metrics);
            if ($success) {
                $this->stdout("Saved.\n");
                $successCount++;
            } else {
                $this->stderr("Failed to save.\n");
            }
        }

        $this->stdout("Successfully synced {$successCount} / {$days} days.\n");
        return ExitCode::OK;
    }
}

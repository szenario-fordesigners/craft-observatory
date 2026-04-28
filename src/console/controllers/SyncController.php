<?php

namespace szenario\craftumamiis\console\controllers;

use craft\console\Controller;
use szenario\craftumamiis\helpers\UmamiTime;
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
     * Defaults to last 30 days.
     * Example: `craft umami-is/sync/historical 30 8`
     * 
     * @param int $days Number of days to pull
     * @param int $concurrency Number of concurrent requests
     * @return int
     */
    public function actionHistorical(int $days = 30, int $concurrency = 8): int
    {
        $this->stdout("Starting sync for the last {$days} days of Umami stats (concurrency {$concurrency})...\n");

        $plugin = UmamiIs::getInstance();
        $successCount = 0;

        $daySpecs = [];
        for ($i = 1; $i <= $days; $i++) {
            $dateStr = UmamiTime::dateOffset($i);
            [$startAt, $endAt] = UmamiTime::dayBounds($dateStr);

            $daySpecs[] = [
                'date' => $dateStr,
                'startAt' => $startAt,
                'endAt' => $endAt,
            ];
        }

        $metricsTypes = ['url', 'title', 'referrer', 'os', 'browser', 'device', 'country', 'region', 'city'];

        $batch = $plugin->client->getDailyStatsAndMetricsBatch($daySpecs, $metricsTypes, $concurrency);

        foreach ($daySpecs as $day) {
            $dateStr = $day['date'];
            $this->stdout("Fetching: {$dateStr}... ");

            $row = $batch[$dateStr] ?? null;
            $stats = $row['stats'] ?? null;
            $metrics = $row['metrics'] ?? [];
            $errors = $row['errors'] ?? [];

            if (empty($stats)) {
                $this->stderr("Failed to load basic stats.\n");
                if (!empty($errors)) {
                    foreach ($errors as $err) {
                        $this->stderr("  - {$err}\n");
                    }
                }
                continue;
            }

            $success = $plugin->sync->syncDailyStats($dateStr, $stats, $metrics);
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

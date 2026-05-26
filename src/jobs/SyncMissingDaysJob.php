<?php

namespace szenario\craftumamiis\jobs;

use Craft;
use craft\queue\BaseJob;
use szenario\craftumamiis\helpers\UmamiTime;
use szenario\craftumamiis\records\DailyStats;
use szenario\craftumamiis\records\HourlyStats;
use szenario\craftumamiis\UmamiIs;

/**
 * Fetches any missing historical DailyStats rows from Umami in the background.
 */
class SyncMissingDaysJob extends BaseJob
{
    public string $websiteId = '';
    public int $days = 30;

    public function execute($queue): void
    {
        // Release the PHP session lock the web-based queue runner holds while
        // this job runs — otherwise parallel widget AJAX requests on the dashboard
        // block on session_start() for the full sync duration (~10–30s on first install).
        if (Craft::$app->getRequest()->getIsWebRequest()) {
            Craft::$app->getSession()->close();
        }

        $cache = Craft::$app->getCache();
        $pendingKey = "umami_autosync_pending_{$this->websiteId}";
        $startTime = microtime(true);

        Craft::info("SyncMissingDaysJob starting: websiteId={$this->websiteId}, days={$this->days}.", 'umami-is');

        try {
            $plugin = UmamiIs::getInstance();

            $todayStr = UmamiTime::dateOffset(0);

            // Build the full set of historical dates in the window (excluding today).
            $allDates = [];
            for ($i = 1; $i <= $this->days; $i++) {
                $ds = UmamiTime::dateOffset($i);
                if ($ds !== $todayStr) {
                    $allDates[$ds] = true;
                }
            }

            if (empty($allDates)) {
                Craft::info('SyncMissingDaysJob: no candidate dates in window.', 'umami-is');
                return;
            }

            $startDateStr = min(array_keys($allDates));

            // — Daily stats pass —
            $wantedDaily = $allDates;
            $existingDaily = DailyStats::find()
                ->select(['date'])
                ->where(['websiteId' => $this->websiteId])
                ->andWhere(['>=', 'date', $startDateStr])
                ->column();

            foreach ($existingDaily as $d) {
                unset($wantedDaily[$d]);
            }

            if (!empty($wantedDaily)) {
                $daySpecs = [];
                foreach (array_keys($wantedDaily) as $ds) {
                    [$startAt, $endAt] = UmamiTime::dayBounds($ds);
                    $daySpecs[] = ['date' => $ds, 'startAt' => $startAt, 'endAt' => $endAt];
                }

                $missingCount = \count($daySpecs);
                Craft::info("SyncMissingDaysJob: {$missingCount} missing daily stats day(s) — fetching.", 'umami-is');
                Craft::debug('SyncMissingDaysJob missing dates: ' . implode(', ', array_keys($wantedDaily)), 'umami-is');

                $metricsTypes = ['url', 'title', 'referrer', 'os', 'browser', 'device', 'country', 'region', 'city'];
                $batch = $plugin->client->getDailyStatsAndMetricsBatch($daySpecs, $metricsTypes);

                $total = \count($daySpecs);
                $done = 0;
                $synced = 0;
                $failed = 0;
                foreach ($daySpecs as $day) {
                    $ds = $day['date'];
                    $row = $batch[$ds] ?? null;
                    $stats = $row['stats'] ?? null;
                    $errors = $row['errors'] ?? [];

                    if (empty($stats)) {
                        $failed++;
                        $reason = $errors ? implode('; ', $errors) : 'empty stats response';
                        Craft::warning("SyncMissingDaysJob: failed for {$ds} — {$reason}", 'umami-is');
                    } elseif ($plugin->sync->syncDailyStats($ds, $stats, $row['metrics'] ?? [])) {
                        $synced++;
                        Craft::debug("SyncMissingDaysJob: saved {$ds}.", 'umami-is');
                    } else {
                        $failed++;
                        Craft::warning("SyncMissingDaysJob: DB save failed for {$ds}.", 'umami-is');
                    }

                    $done++;
                    $this->setProgress($queue, $done / $total * 0.5);
                }

                $elapsed = number_format(microtime(true) - $startTime, 2);
                Craft::info("SyncMissingDaysJob daily pass: synced={$synced}, failed={$failed}, total={$total}, elapsed={$elapsed}s.", 'umami-is');
            } else {
                Craft::info('SyncMissingDaysJob: all daily stats already present.', 'umami-is');
            }

            // — Hourly stats pass —
            // Always runs so that days present before hourly tracking was added get backfilled.
            $wantedHourly = $allDates;
            $existingHourlyDates = HourlyStats::find()
                ->select(['date'])
                ->where(['websiteId' => $this->websiteId])
                ->andWhere(['>=', 'date', $startDateStr])
                ->distinct()
                ->column();

            foreach ($existingHourlyDates as $d) {
                unset($wantedHourly[$d]);
            }

            if (!empty($wantedHourly)) {
                $hourlyDaySpecs = [];
                foreach (array_keys($wantedHourly) as $ds) {
                    [$hStart, $hEnd] = UmamiTime::dayBounds($ds);
                    $hourlyDaySpecs[] = ['date' => $ds, 'startAt' => $hStart, 'endAt' => $hEnd];
                }

                Craft::info('SyncMissingDaysJob: fetching hourly data for ' . \count($hourlyDaySpecs) . ' day(s).', 'umami-is');
                $hourlyBatch = $plugin->client->getHourlyPageviewsBatch($hourlyDaySpecs);

                $hDone = 0;
                $hTotal = \count($hourlyDaySpecs);
                foreach ($hourlyDaySpecs as $hDay) {
                    $hourlyRows = $hourlyBatch[$hDay['date']] ?? [];
                    if (!empty($hourlyRows)) {
                        $plugin->sync->syncHourlyStats($hDay['date'], $hourlyRows);
                    }
                    $hDone++;
                    $this->setProgress($queue, 0.5 + $hDone / $hTotal * 0.5);
                }
            } else {
                Craft::info('SyncMissingDaysJob: all hourly stats already present.', 'umami-is');
            }

            // — Events pass (rolling UmamiIs::EVENTS_CLOSED_DAYS window) —
            // Always refreshes events across the last N closed days so late-arriving
            // events surface, regardless of whether the daily row already exists.
            $eventDaySpecs = [];
            for ($i = 1; $i <= UmamiIs::EVENTS_CLOSED_DAYS; $i++) {
                $ds = UmamiTime::dateOffset($i);
                [$eStart, $eEnd] = UmamiTime::dayBounds($ds);
                $eventDaySpecs[] = ['date' => $ds, 'startAt' => $eStart, 'endAt' => $eEnd];
            }

            if (!empty($eventDaySpecs)) {
                Craft::info('SyncMissingDaysJob: fetching events for ' . \count($eventDaySpecs) . ' day(s).', 'umami-is');
                $eventsBatch = $plugin->client->getEventsBatch($eventDaySpecs);

                $eSynced = 0;
                $eFailed = 0;
                foreach ($eventDaySpecs as $eDay) {
                    $ds = $eDay['date'];
                    if (!\array_key_exists($ds, $eventsBatch)) {
                        // Fetch failed for this day — leave existing rows untouched
                        // rather than wiping them with an empty write.
                        $eFailed++;
                        Craft::warning("SyncMissingDaysJob: events fetch failed for {$ds} — leaving existing rows intact.", 'umami-is');
                        continue;
                    }
                    if ($plugin->sync->syncDailyEvents($ds, $eventsBatch[$ds])) {
                        $eSynced++;
                    } else {
                        $eFailed++;
                    }
                }

                Craft::info("SyncMissingDaysJob events pass: synced={$eSynced}, failed={$eFailed}.", 'umami-is');
            }

            $elapsed = number_format(microtime(true) - $startTime, 2);
            Craft::info("SyncMissingDaysJob finished in {$elapsed}s.", 'umami-is');
        } finally {
            $cache->delete($pendingKey);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('umami-is', 'Syncing missing Umami daily stats');
    }
}

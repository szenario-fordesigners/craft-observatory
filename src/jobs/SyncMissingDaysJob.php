<?php

namespace szenario\craftumamiis\jobs;

use Craft;
use craft\queue\BaseJob;
use szenario\craftumamiis\records\DailyStats;
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
        $cache = Craft::$app->getCache();
        $pendingKey = "umami_autosync_pending_{$this->websiteId}";
        $startTime = microtime(true);

        Craft::info("SyncMissingDaysJob starting: websiteId={$this->websiteId}, days={$this->days}.", 'umami-is');

        try {
            $analytics = UmamiIs::getInstance()->analytics;

            $todayStr = date('Y-m-d');
            $wanted = [];
            for ($i = 1; $i <= $this->days; $i++) {
                $dateStr = date('Y-m-d', strtotime("-{$i} days"));
                if ($dateStr === $todayStr) {
                    continue;
                }
                $wanted[$dateStr] = true;
            }

            if (empty($wanted)) {
                Craft::info('SyncMissingDaysJob: no candidate dates in window.', 'umami-is');
                return;
            }

            $startDateStr = min(array_keys($wanted));
            $existing = DailyStats::find()
                ->select(['date'])
                ->where(['websiteId' => $this->websiteId])
                ->andWhere(['>=', 'date', $startDateStr])
                ->column();

            foreach ($existing as $existingDate) {
                unset($wanted[date('Y-m-d', strtotime($existingDate))]);
            }

            if (empty($wanted)) {
                Craft::info('SyncMissingDaysJob: all days already present in DB, nothing to fetch.', 'umami-is');
                return;
            }

            $daySpecs = [];
            foreach (array_keys($wanted) as $dateStr) {
                $daySpecs[] = [
                    'date' => $dateStr,
                    'startAt' => strtotime($dateStr . ' midnight') * 1000,
                    'endAt' => strtotime($dateStr . ' 23:59:59') * 1000,
                ];
            }

            $missingCount = count($daySpecs);
            Craft::info("SyncMissingDaysJob: {$missingCount} missing day(s) — fetching from Umami.", 'umami-is');
            Craft::debug('SyncMissingDaysJob missing dates: ' . implode(', ', array_keys($wanted)), 'umami-is');

            $metricsTypes = ['url', 'title', 'referrer', 'os', 'browser', 'device', 'country', 'region', 'city'];
            $batch = $analytics->getDailyStatsAndMetricsBatch($daySpecs, $metricsTypes);

            $total = count($daySpecs);
            $done = 0;
            $synced = 0;
            $failed = 0;
            foreach ($daySpecs as $day) {
                $dateStr = $day['date'];
                $row = $batch[$dateStr] ?? null;
                $stats = $row['stats'] ?? null;
                $errors = $row['errors'] ?? [];

                if (empty($stats)) {
                    $failed++;
                    $reason = $errors ? implode('; ', $errors) : 'empty stats response';
                    Craft::warning("SyncMissingDaysJob: failed for {$dateStr} — {$reason}", 'umami-is');
                } elseif ($analytics->syncDailyStats($dateStr, $stats, $row['metrics'] ?? [])) {
                    $synced++;
                    Craft::debug("SyncMissingDaysJob: saved {$dateStr}.", 'umami-is');
                } else {
                    $failed++;
                    Craft::warning("SyncMissingDaysJob: DB save failed for {$dateStr}.", 'umami-is');
                }

                $done++;
                $this->setProgress($queue, $done / $total);
            }

            $elapsed = number_format(microtime(true) - $startTime, 2);
            Craft::info("SyncMissingDaysJob done: synced={$synced}, failed={$failed}, total={$total}, elapsed={$elapsed}s.", 'umami-is');
        } finally {
            $cache->delete($pendingKey);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('umami-is', 'Syncing missing Umami daily stats');
    }
}

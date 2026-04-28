<?php

namespace szenario\craftumamiis\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use szenario\craftumamiis\jobs\SyncMissingDaysJob;
use szenario\craftumamiis\records\DailyStats;
use szenario\craftumamiis\UmamiIs;

/**
 * Coordinates background syncing of historical Umami stats into the local DB.
 *
 * autoSyncMissingDays() is the render-path entry: it throttles, dedupes, and queues
 * SyncMissingDaysJob. The job is the sole writer of historical rows; render paths
 * never write inline. syncDailyStats() is the persistence routine the job calls back into.
 */
class SyncCoordinator extends Component
{
    /**
     * Queues a background job to sync any historical days missing from the local DB.
     * Render-path cost: cache checks, one indexed query for MAX(dateUpdated), and at most one queue insert.
     * Today is always skipped — it's still accumulating and handled by the live widget queries.
     *
     * @param int $days How many days back to check (excluding today).
     * @param int $throttleSeconds Minimum seconds between sync attempts.
     * @return bool True if a job was queued, false if throttled or already pending.
     */
    public function autoSyncMissingDays(int $days = 30, int $throttleSeconds = 900): bool
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::warning('autoSyncMissingDays skipped: no websiteId configured.', 'umami-is');
            return false;
        }

        $cache = Craft::$app->getCache();
        $lastAttemptKey = "umami_autosync_last_attempt_{$websiteId}";
        if ($cache->get($lastAttemptKey) !== false) {
            Craft::debug("autoSyncMissingDays throttled: a sync was attempted within the last {$throttleSeconds}s.", 'umami-is');
            return false;
        }

        $lastUpdatedStr = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->max('dateUpdated');

        if ($lastUpdatedStr !== null && time() - strtotime($lastUpdatedStr) < $throttleSeconds) {
            $age = time() - strtotime($lastUpdatedStr);
            Craft::debug("autoSyncMissingDays throttled: last sync {$age}s ago (window {$throttleSeconds}s).", 'umami-is');
            return false;
        }

        // Dedupe pending jobs across rapid concurrent renders. The job itself clears this key on completion.
        $pendingKey = "umami_autosync_pending_{$websiteId}";
        if (!$cache->add($pendingKey, 1, $throttleSeconds)) {
            Craft::debug('autoSyncMissingDays skipped: a sync job is already pending.', 'umami-is');
            return false;
        }

        $cache->set($lastAttemptKey, 1, $throttleSeconds);

        Craft::$app->getQueue()->push(new SyncMissingDaysJob([
            'websiteId' => $websiteId,
            'days' => $days,
        ]));

        Craft::info("Queued SyncMissingDaysJob for websiteId={$websiteId}, days={$days}.", 'umami-is');
        return true;
    }

    /**
     * Saves daily stats retrieved from Umami into the local database.
     *
     * @param string $date Date in 'Y-m-d' format.
     * @param array $stats Raw stats array from the API.
     * @param array $metrics Optional metrics keyed by type. Empty input preserves existing metrics on update.
     */
    public function syncDailyStats(string $date, array $stats, array $metrics = []): bool
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::error('Cannot sync stats without an Umami Website ID.', __METHOD__);
            return false;
        }

        $record = DailyStats::findOne([
            'websiteId' => $websiteId,
            'date' => $date,
        ]);

        if (!$record) {
            $record = new DailyStats();
            $record->websiteId = $websiteId;
            $record->date = $date;
        }

        $record->pageviews = (int) ($stats['pageviews'] ?? 0);
        $record->visitors = (int) ($stats['visitors'] ?? 0);
        $record->visits = (int) ($stats['visits'] ?? 0);
        $record->bounces = (int) ($stats['bounces'] ?? 0);
        $record->totaltime = (int) ($stats['totaltime'] ?? 0);

        if (!empty($metrics)) {
            $record->metrics = json_encode($metrics);
        }

        if (!$record->save()) {
            Craft::error("Failed to save daily stats for {$date}: " . json_encode($record->getErrors()), __METHOD__);
            return false;
        }

        return true;
    }
}

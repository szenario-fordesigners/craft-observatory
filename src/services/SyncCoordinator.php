<?php

namespace szenario\craftumamiis\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use szenario\craftumamiis\jobs\SyncMissingDaysJob;
use szenario\craftumamiis\records\DailyStats;
use szenario\craftumamiis\records\HourlyStats;
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
    public function autoSyncMissingDays(int $days = 30, int $throttleSeconds = 2): bool
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::warning('autoSyncMissingDays skipped: no websiteId configured.', 'umami-is');
            return false;
        }

        $lastUpdatedStr = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->max('dateUpdated');

        $isFirstRun = $lastUpdatedStr === null;

        if (!$isFirstRun) {
            $cache = Craft::$app->getCache();
            $lastAttemptKey = "umami_autosync_last_attempt_{$websiteId}";
            if ($cache->get($lastAttemptKey) !== false) {
                Craft::debug("autoSyncMissingDays throttled: a sync was attempted within the last {$throttleSeconds}s.", 'umami-is');
                return false;
            }

            $lastUpdatedTs = (new \DateTime($lastUpdatedStr, new \DateTimeZone('UTC')))->getTimestamp();
            if (time() - $lastUpdatedTs < $throttleSeconds) {
                $age = time() - $lastUpdatedTs;
                Craft::debug("autoSyncMissingDays throttled: last sync {$age}s ago (window {$throttleSeconds}s).", 'umami-is');
                return false;
            }
        }

        $cache = Craft::$app->getCache();

        // Dedupe pending jobs across rapid concurrent renders. The pending value stores
        // the `days` window of the queued job, so a request that needs a wider backfill
        // is still allowed through when an already-pending job covers fewer days.
        // The job itself clears this key on completion.
        $pendingKey = "umami_autosync_pending_{$websiteId}";
        $existingPending = $cache->get($pendingKey);
        if ($existingPending !== false && (int) $existingPending >= $days) {
            Craft::debug("autoSyncMissingDays skipped: pending job already covers {$existingPending} day(s).", 'umami-is');
            return false;
        }
        $cache->set($pendingKey, $days, $throttleSeconds);

        if (!$isFirstRun) {
            $cache->set("umami_autosync_last_attempt_{$websiteId}", 1, $throttleSeconds);
        }

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

    /**
     * Replaces the umami_daily_events rows for a single date with the given top-events list.
     *
     * Runs in a transaction so the day's rows are never partially updated. Used by the
     * rolling 7-day events sync pass — each call deletes the day's prior rows and inserts
     * the latest snapshot, so events that disappear from Umami also disappear locally.
     *
     * @param string $date Date in 'Y-m-d' format.
     * @param array<int,array{x:string,y:int|float}> $events Top events from Umami /metrics?type=event.
     */
    public function syncDailyEvents(string $date, array $events): bool
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::error('Cannot sync events without an Umami Website ID.', __METHOD__);
            return false;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()
                ->delete('{{%umami_daily_events}}', [
                    'websiteId' => $websiteId,
                    'date' => $date,
                ])
                ->execute();

            $rows = [];
            $now = Db::prepareDateForDb(new \DateTime());
            foreach ($events as $event) {
                $name = (string) ($event['x'] ?? '');
                if ($name === '') {
                    continue;
                }
                $rows[] = [
                    $websiteId,
                    $date,
                    $name,
                    (int) ($event['y'] ?? 0),
                    $now,
                    $now,
                    StringHelper::UUID(),
                ];
            }

            if (!empty($rows)) {
                $db->createCommand()
                    ->batchInsert(
                        '{{%umami_daily_events}}',
                        ['websiteId', 'date', 'eventName', 'total', 'dateCreated', 'dateUpdated', 'uid'],
                        $rows
                    )
                    ->execute();
            }

            $transaction->commit();
            return true;
        } catch (\Throwable $e) {
            $transaction->rollBack();
            Craft::error("Failed to sync events for {$date}: {$e->getMessage()}", __METHOD__);
            return false;
        }
    }

    /**
     * Upserts hourly visitor/pageview rows for a single date.
     *
     * @param string $date Date in 'Y-m-d' format.
     * @param array<int,array{hour:int,visitors:int,pageviews:int}> $hourlyRows
     */
    public function syncHourlyStats(string $date, array $hourlyRows): bool
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::error('Cannot sync hourly stats without an Umami Website ID.', __METHOD__);
            return false;
        }

        foreach ($hourlyRows as $row) {
            $hour = (int) $row['hour'];

            $record = HourlyStats::findOne([
                'websiteId' => $websiteId,
                'date' => $date,
                'hour' => $hour,
            ]);

            if (!$record) {
                $record = new HourlyStats();
                $record->websiteId = $websiteId;
                $record->date = $date;
                $record->hour = $hour;
            }

            $record->visitors = (int) $row['visitors'];
            $record->pageviews = (int) $row['pageviews'];

            if (!$record->save()) {
                Craft::error(
                    "Failed to save hourly stats for {$date} hour {$hour}: " . json_encode($record->getErrors()),
                    __METHOD__
                );
                return false;
            }
        }

        return true;
    }
}

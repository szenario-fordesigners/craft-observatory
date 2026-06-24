<?php

namespace szenario\craftobservatory\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use szenario\craftobservatory\helpers\AnalyticsTime;
use szenario\craftobservatory\jobs\SyncDailyStatsJob;
use szenario\craftobservatory\jobs\SyncRecentDaysJob;
use szenario\craftobservatory\records\DailyStats;
use szenario\craftobservatory\records\HourlyStats;
use szenario\craftobservatory\Observatory;

/**
 * Coordinates background syncing of historical analytics stats into the local DB.
 *
 * Every closed day is fetched exactly once — a day is considered done once it has a
 * DailyStats row, and is never re-fetched. Today is never fetched (the live widget
 * queries handle it). autoSyncMissingDays() is the render-path entry: it throttles,
 * dedupes, and queues the sync jobs. SyncRecentDaysJob fetches any unsynced days in
 * the rolling recent window (daily + hourly + events); SyncDailyStatsJob backfills
 * older unsynced days (daily + hourly) in fixed-size chunks so a large window never
 * times out a single job. The jobs are the sole writers of historical rows; render
 * paths never write inline. The jobs call back into the fetchAndStore*() / sync*()
 * routines here to do the actual fetching and persistence.
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
    public function autoSyncMissingDays(int $days = 30, int $throttleSeconds = 300): bool
    {
        $websiteId = Observatory::getInstance()->analytics->getStorageKey();

        if (empty($websiteId)) {
            Craft::warning('autoSyncMissingDays skipped: no analytics storage key configured.', 'observatory');
            return false;
        }

        $cache = Craft::$app->getCache();
        $timeGuardWasReset = $cache->get($this->_autoSyncTimeGuardResetCacheKey($websiteId)) === true;

        $lastUpdatedStr = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->max('dateUpdated');

        $isFirstRun = $lastUpdatedStr === null;

        if (!$isFirstRun && !$timeGuardWasReset) {
            $lastAttemptKey = $this->_autoSyncLastAttemptCacheKey($websiteId);
            if ($cache->get($lastAttemptKey) !== false) {
                Craft::debug("autoSyncMissingDays throttled: a sync was attempted within the last {$throttleSeconds}s.", 'observatory');
                return false;
            }

            $lastUpdatedTs = (new \DateTime($lastUpdatedStr, new \DateTimeZone('UTC')))->getTimestamp();
            if (time() - $lastUpdatedTs < $throttleSeconds) {
                $age = time() - $lastUpdatedTs;
                Craft::debug("autoSyncMissingDays throttled: last sync {$age}s ago (window {$throttleSeconds}s).", 'observatory');
                return false;
            }
        }

        // Dedupe pending jobs across rapid concurrent renders. The pending value stores
        // the `days` window already queued, so a request that needs a wider backfill is
        // still allowed through when the pending coverage spans fewer days. The key
        // expires on its own after $throttleSeconds. Jobs reset the time guard, but not
        // this pending coverage guard, otherwise queued chunks could be duplicated.
        $pendingKey = "observatory_autosync_pending_{$websiteId}";
        $existingPending = $cache->get($pendingKey);
        if ($existingPending !== false && (int) $existingPending >= $days) {
            Craft::debug("autoSyncMissingDays skipped: pending jobs already cover {$existingPending} day(s).", 'observatory');
            return false;
        }
        $cache->set($pendingKey, $days, $throttleSeconds);

        if (!$isFirstRun) {
            $cache->set($this->_autoSyncLastAttemptCacheKey($websiteId), 1, $throttleSeconds);
        }

        if ($timeGuardWasReset) {
            $cache->delete($this->_autoSyncTimeGuardResetCacheKey($websiteId));
        }

        $queue = Craft::$app->getQueue();

        // Fetch any unsynced days in the rolling recent window (daily + hourly + events).
        // Already-synced days are skipped — each day is fetched exactly once.
        $recentDays = Observatory::EVENTS_CLOSED_DAYS;
        $queue->push(new SyncRecentDaysJob([
            'websiteId' => $websiteId,
        ]));

        // Backfill everything older than the recent window in fixed-size chunks so a
        // large window never times out a single job. All chunks are queued up front.
        $batchCount = 0;
        for ($start = $recentDays + 1; $start <= $days; $start += SyncDailyStatsJob::BATCH_SIZE) {
            $end = min($start + SyncDailyStatsJob::BATCH_SIZE - 1, $days);
            $queue->push(new SyncDailyStatsJob([
                'websiteId' => $websiteId,
                'startOffset' => $start,
                'endOffset' => $end,
            ]));
            $batchCount++;
        }

        Craft::info(
            "Queued SyncRecentDaysJob + {$batchCount} SyncDailyStatsJob batch(es) for websiteId={$websiteId}, days={$days}.",
            'observatory'
        );
        return true;
    }

    /**
     * Lets the next auto-sync enqueue immediately after a backfill job completes.
     *
     * This resets only the time-based guards. The pending coverage guard is left
     * intact so already-queued chunks are not duplicated by rapid dashboard polling.
     *
     * @author szenario
     * @since 1.0.0
     */
    public function resetAutoSyncTimeGuard(string $websiteId): void
    {
        if ($websiteId === '') {
            return;
        }

        $cache = Craft::$app->getCache();
        $cache->delete($this->_autoSyncLastAttemptCacheKey($websiteId));
        $cache->set($this->_autoSyncTimeGuardResetCacheKey($websiteId), true, 600);
    }

    /**
     * Returns day specs for the days in the offset range [startOffset, endOffset] that
     * have not been fetched yet — i.e. that have no DailyStats row. Today is always
     * excluded. The DailyStats row is the single "this day is done" marker, so daily,
     * hourly and events all sync together for a day and never re-fetch once it exists.
     *
     * @return array<int,array{date:string,startAt:int,endAt:int}>
     */
    public function findUnsyncedDaySpecs(string $websiteId, int $startOffset, int $endOffset): array
    {
        $todayStr = AnalyticsTime::dateOffset(0);

        $candidates = [];
        for ($i = $startOffset; $i <= $endOffset; $i++) {
            $ds = AnalyticsTime::dateOffset($i);
            if ($ds !== $todayStr) {
                $candidates[$ds] = true;
            }
        }

        if (empty($candidates)) {
            return [];
        }

        $startDateStr = min(array_keys($candidates));
        $existing = DailyStats::find()
            ->select(['date'])
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $startDateStr])
            ->column();
        foreach ($existing as $d) {
            unset($candidates[$d]);
        }

        $specs = [];
        foreach (array_keys($candidates) as $ds) {
            [$startAt, $endAt] = AnalyticsTime::dayBounds($ds);
            $specs[] = ['date' => $ds, 'startAt' => $startAt, 'endAt' => $endAt];
        }

        return $specs;
    }

    /**
     * Fetches daily stats + metrics from the analytics source for the given day specs and persists them.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $daySpecs
     * @param callable|null $onProgress fn(int $done, int $total): void — called after each day.
     * @return array{synced:int,failed:int}
     */
    public function fetchAndStoreDailyStats(array $daySpecs, ?callable $onProgress = null): array
    {
        if (empty($daySpecs)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $plugin = Observatory::getInstance();
        $metricsTypes = ['url', 'title', 'referrer', 'os', 'browser', 'device', 'country', 'region', 'city'];
        $batch = $plugin->analytics->getDailyStatsAndBreakdownsBatch($daySpecs, $metricsTypes);

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
                Craft::warning("fetchAndStoreDailyStats: failed for {$ds} — {$reason}", 'observatory');
            } elseif ($this->syncDailyStats($ds, $stats, $row['metrics'] ?? [])) {
                $synced++;
                Craft::debug("fetchAndStoreDailyStats: saved {$ds}.", 'observatory');
            } else {
                $failed++;
                Craft::warning("fetchAndStoreDailyStats: DB save failed for {$ds}.", 'observatory');
            }

            $done++;
            if ($onProgress !== null) {
                $onProgress($done, $total);
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Fetches hourly pageviews from the analytics source for the given day specs and persists them.
     *
     * Days with no hourly rows (zero-traffic days) are a no-op and counted as neither
     * synced nor failed; `failed` reflects only days whose DB write actually failed.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $daySpecs
     * @param callable|null $onProgress fn(int $done, int $total): void — called after each day.
     * @return array{synced:int,failed:int}
     */
    public function fetchAndStoreHourlyStats(array $daySpecs, ?callable $onProgress = null): array
    {
        if (empty($daySpecs)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $plugin = Observatory::getInstance();
        $hourlyBatch = $plugin->analytics->getHourlyPageviewsBatch($daySpecs);

        $total = \count($daySpecs);
        $done = 0;
        $synced = 0;
        $failed = 0;
        foreach ($daySpecs as $day) {
            $hourlyRows = $hourlyBatch[$day['date']] ?? [];
            if (!empty($hourlyRows)) {
                if ($this->syncHourlyStats($day['date'], $hourlyRows)) {
                    $synced++;
                } else {
                    $failed++;
                }
            }
            $done++;
            if ($onProgress !== null) {
                $onProgress($done, $total);
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Fetches top events from the analytics source for the given day specs and refreshes the local rows.
     * A failed fetch for a day leaves that day's existing rows intact rather than wiping them.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $daySpecs
     * @return array{synced:int,failed:int}
     */
    public function fetchAndStoreEvents(array $daySpecs): array
    {
        if (empty($daySpecs)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $plugin = Observatory::getInstance();
        $eventsBatch = $plugin->analytics->getEventsBatch($daySpecs);

        $synced = 0;
        $failed = 0;
        foreach ($daySpecs as $day) {
            $ds = $day['date'];
            if (!\array_key_exists($ds, $eventsBatch)) {
                $failed++;
                Craft::warning("fetchAndStoreEvents: events fetch failed for {$ds} — leaving existing rows intact.", 'observatory');
                continue;
            }
            if ($this->syncDailyEvents($ds, $eventsBatch[$ds])) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Saves daily stats retrieved from the analytics source into the local database.
     *
     * @param string $date Date in 'Y-m-d' format.
     * @param array $stats Raw stats array from the API.
     * @param array $metrics Optional metrics keyed by type. Empty input preserves existing metrics on update.
     */
    public function syncDailyStats(string $date, array $stats, array $metrics = []): bool
    {
        $websiteId = Observatory::getInstance()->analytics->getStorageKey();

        if (empty($websiteId)) {
            Craft::error('Cannot sync stats without an analytics storage key.', __METHOD__);
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
        $record->sessionDurationSeconds = (int) ($stats['sessionDurationSeconds'] ?? 0);

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
     * Replaces the observatory_daily_events rows for a single date with the given top-events list.
     *
     * Runs in a transaction so the day's rows are never partially updated. The delete
     * before insert keeps the write idempotent if the day is ever re-synced, but in
     * normal operation each day's events are fetched exactly once.
     *
     * @param string $date Date in 'Y-m-d' format.
     * @param array<int,array{x:string,y:int|float}> $events Top events from the analytics source.
     */
    public function syncDailyEvents(string $date, array $events): bool
    {
        $websiteId = Observatory::getInstance()->analytics->getStorageKey();

        if (empty($websiteId)) {
            Craft::error('Cannot sync events without an analytics storage key.', __METHOD__);
            return false;
        }

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $db->createCommand()
                ->delete('{{%observatory_daily_events}}', [
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
                        '{{%observatory_daily_events}}',
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
        $websiteId = Observatory::getInstance()->analytics->getStorageKey();

        if (empty($websiteId)) {
            Craft::error('Cannot sync hourly stats without an analytics storage key.', __METHOD__);
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

    /**
     * Returns the cache key for the autosync last-attempt guard.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _autoSyncLastAttemptCacheKey(string $websiteId): string
    {
        return "observatory_autosync_last_attempt_{$websiteId}";
    }

    /**
     * Returns the cache key that lets the next autosync bypass time throttling.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _autoSyncTimeGuardResetCacheKey(string $websiteId): string
    {
        return "observatory_autosync_time_guard_reset_{$websiteId}";
    }
}

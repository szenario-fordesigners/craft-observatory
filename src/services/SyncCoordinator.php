<?php

namespace szenario\craftobservatory\services;

use Craft;
use craft\base\Component;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use szenario\craftobservatory\helpers\AnalyticsTime;
use szenario\craftobservatory\jobs\SyncDailyStatsJob;
use szenario\craftobservatory\jobs\SyncRecentDaysJob;
use szenario\craftobservatory\Observatory;
use szenario\craftobservatory\records\DailyStats;
use szenario\craftobservatory\records\HourlyStats;
use szenario\craftobservatory\records\SyncState;

/**
 * Coordinates background syncing of historical analytics stats into the local DB.
 *
 * Completeness is tracked per day, per *facet* (daily / breakdowns / hourly / events)
 * in {@see SyncState}, not by "a DailyStats row exists". A facet that errors is retried
 * on later passes, bounded by {@see self::SYNC_MAX_ATTEMPTS}; a facet that succeeds
 * (including a legitimately empty result) is done and never re-fetched. Today is never
 * fetched (the live widget queries handle it). autoSyncMissingDays() is the render-path
 * entry: it throttles, dedupes, and queues the sync jobs. SyncRecentDaysJob fetches the
 * rolling recent window (daily + breakdowns + hourly + events); SyncDailyStatsJob
 * backfills older days (daily + breakdowns + hourly — no events) in fixed-size chunks.
 * The jobs are the sole writers of historical rows; render paths never write inline.
 * The jobs call back into the fetchAndStore*() / sync*() routines here.
 */
class SyncCoordinator extends Component
{
    /** Sync facets — independently fetched and independently retryable units of a day. */
    public const FACET_DAILY = 'daily';
    public const FACET_BREAKDOWNS = 'breakdowns';
    public const FACET_HOURLY = 'hourly';
    public const FACET_EVENTS = 'events';

    /** SyncState statuses. Absence of a row means "never attempted" (pending). */
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    /**
     * How many times a failed facet is retried before it's left alone. Bounds retry so a
     * structurally-broken facet (e.g. a session column a provider doesn't expose) can't
     * re-fetch the same day forever. Successive passes are already spaced by the
     * autoSyncMissingDays() throttle, so this needs no separate time backoff.
     */
    public const SYNC_MAX_ATTEMPTS = 3;

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
        if ($cache->get($this->_autoSyncDeferredCacheKey($websiteId)) !== false) {
            Craft::debug('autoSyncMissingDays skipped: analytics provider cooldown is active.', 'observatory');
            return false;
        }
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

        // Serialize the read/update/enqueue sequence across concurrent widget requests.
        $enqueueLockKey = $this->_autoSyncEnqueueLockCacheKey($websiteId);
        if (!$cache->add($enqueueLockKey, true, 10)) {
            Craft::debug('autoSyncMissingDays skipped: another request is enqueueing sync jobs.', 'observatory');
            return false;
        }

        try {
            // The pending value stores the widest queued window. Widening an existing
            // window queues only the uncovered offsets, never duplicate recent/chunk jobs.
            $pendingKey = "observatory_autosync_pending_{$websiteId}";
            $existingPending = $cache->get($pendingKey);
            $coveredDays = $existingPending === false ? 0 : (int) $existingPending;
            if ($coveredDays >= $days) {
                Craft::debug("autoSyncMissingDays skipped: pending jobs already cover {$coveredDays} day(s).", 'observatory');
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

            // The recent job is needed only when it isn't already covered.
            $recentDays = Observatory::EVENTS_CLOSED_DAYS;
            if ($coveredDays < $recentDays) {
                $queue->push(new SyncRecentDaysJob([
                    'websiteId' => $websiteId,
                ]));
            }

            // Queue only backfill offsets not covered by the existing pending window.
            $batchCount = 0;
            foreach ($this->_backfillChunks($days, $coveredDays) as [$start, $end]) {
                $queue->push(new SyncDailyStatsJob([
                    'websiteId' => $websiteId,
                    'startOffset' => $start,
                    'endOffset' => $end,
                ]));
                $batchCount++;
            }

            Craft::info(
                "Queued sync up to {$days} day(s) with {$batchCount} new backfill batch(es) for websiteId={$websiteId}, previousCoverage={$coveredDays}.",
                'observatory'
            );
            return true;
        } finally {
            $cache->delete($enqueueLockKey);
        }
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
     * Defers new auto-sync jobs without consuming per-facet retry attempts.
     */
    public function deferAutoSync(string $websiteId, int $seconds): void
    {
        if ($websiteId === '') {
            return;
        }

        $seconds = max(1, $seconds);
        $cache = Craft::$app->getCache();
        $cache->delete($this->_autoSyncTimeGuardResetCacheKey($websiteId));
        $cache->set($this->_autoSyncDeferredCacheKey($websiteId), true, $seconds);
    }

    /**
     * Returns day specs for days in the offset range [startOffset, endOffset] where at
     * least one of the given facets still needs work — never attempted, or failed and
     * within its retry budget. Today is always excluded.
     *
     * Facets default to all of them. Callers that only cover some facets must pass the
     * relevant subset, or days will look perpetually unsynced: e.g. backfill never
     * fetches events, so it must omit FACET_EVENTS.
     *
     * @param string[]|null $facets Subset of FACET_* to consider; null = all facets.
     * @return array<int,array{date:string,startAt:int,endAt:int}>
     */
    public function findUnsyncedDaySpecs(string $websiteId, int $startOffset, int $endOffset, ?array $facets = null): array
    {
        $facets = $facets ?? self::allFacets();
        $todayStr = AnalyticsTime::dateOffset(0);

        $dates = [];
        for ($i = $startOffset; $i <= $endOffset; $i++) {
            $ds = AnalyticsTime::dateOffset($i);
            if ($ds !== $todayStr) {
                $dates[$ds] = true;
            }
        }

        if (empty($dates)) {
            return [];
        }

        $dates = array_keys($dates);
        $states = $this->_loadFacetStates($websiteId, min($dates), max($dates), $facets);

        $specs = [];
        foreach ($dates as $ds) {
            foreach ($facets as $facet) {
                if ($this->_facetNeedsWork($states["{$ds}|{$facet}"] ?? null)) {
                    [$startAt, $endAt] = AnalyticsTime::dayBounds($ds);
                    $specs[] = ['date' => $ds, 'startAt' => $startAt, 'endAt' => $endAt];
                    break;
                }
            }
        }

        return $specs;
    }

    /**
     * Returns a compact freshness envelope for a closed-day offset window.
     *
     * `_syncing` means at least one requested facet still has retry budget left.
     * `missingDays` includes every date where any requested facet is not done, including
     * exhausted failures. `lastSyncedAt` is the latest successful facet update in the
     * window, useful for "data last refreshed" UI copy.
     *
     * @param string[] $facets
     * @return array{_syncing:bool,lastSyncedAt:?string,missingDays:array<int,string>}
     */
    public function getFreshnessForOffsets(string $websiteId, int $startOffset, int $endOffset, array $facets): array
    {
        $empty = ['_syncing' => false, 'lastSyncedAt' => null, 'missingDays' => []];

        if ($websiteId === '' || empty($facets)) {
            return $empty;
        }

        $startOffset = max(1, $startOffset);
        $endOffset = max(1, $endOffset);
        if ($startOffset > $endOffset) {
            [$startOffset, $endOffset] = [$endOffset, $startOffset];
        }

        $dates = [];
        for ($i = $startOffset; $i <= $endOffset; $i++) {
            $dates[] = AnalyticsTime::dateOffset($i);
        }

        if (empty($dates)) {
            return $empty;
        }

        $states = $this->_loadFacetStates($websiteId, min($dates), max($dates), $facets);
        $syncing = false;
        $missingDays = [];
        $lastSyncedAt = null;

        foreach ($dates as $date) {
            $dayMissing = false;

            foreach ($facets as $facet) {
                $state = $states["{$date}|{$facet}"] ?? null;

                if ($this->_facetNeedsWork($state)) {
                    $syncing = true;
                }

                if ($state === null || $state->status !== self::STATUS_DONE) {
                    $dayMissing = true;
                    continue;
                }

                if ($lastSyncedAt === null || $state->dateUpdated > $lastSyncedAt) {
                    $lastSyncedAt = $state->dateUpdated;
                }
            }

            if ($dayMissing) {
                $missingDays[] = $date;
            }
        }

        return [
            '_syncing' => $syncing,
            'lastSyncedAt' => $lastSyncedAt,
            'missingDays' => $missingDays,
        ];
    }

    /**
     * All sync facets. Recent-window syncs cover all of them; backfill omits events.
     *
     * @return string[]
     */
    public static function allFacets(): array
    {
        return [self::FACET_DAILY, self::FACET_BREAKDOWNS, self::FACET_HOURLY, self::FACET_EVENTS];
    }

    /**
     * Loads SyncState rows for a date range + facet set, keyed "date|facet".
     *
     * @param string[] $facets
     * @return array<string,SyncState>
     */
    private function _loadFacetStates(string $websiteId, string $startDate, string $endDate, array $facets): array
    {
        /** @var SyncState[] $rows */
        $rows = SyncState::find()
            ->where(['websiteId' => $websiteId, 'facet' => $facets])
            ->andWhere(['>=', 'date', $startDate])
            ->andWhere(['<=', 'date', $endDate])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $map["{$row->date}|{$row->facet}"] = $row;
        }

        return $map;
    }

    /**
     * Whether a facet still needs fetching: never attempted, or failed under the cap.
     * A successful facet (status done, including a legitimately empty result) is finished.
     */
    private function _facetNeedsWork(?SyncState $state): bool
    {
        if ($state === null) {
            return true;
        }
        if ($state->status === self::STATUS_DONE) {
            return false;
        }

        return (int) $state->attempts < self::SYNC_MAX_ATTEMPTS;
    }

    /**
     * Records the outcome of a facet fetch for a day. Success marks it done; failure flips
     * it to failed and bumps the attempt count (which the retry budget caps).
     */
    private function _markFacet(string $websiteId, string $date, string $facet, bool $success): void
    {
        $state = SyncState::findOne([
            'websiteId' => $websiteId,
            'date' => $date,
            'facet' => $facet,
        ]);

        if ($state === null) {
            $state = new SyncState();
            $state->websiteId = $websiteId;
            $state->date = $date;
            $state->facet = $facet;
            $state->attempts = 0;
        }

        if ($success) {
            $state->status = self::STATUS_DONE;
        } else {
            $state->status = self::STATUS_FAILED;
            $state->attempts = (int) $state->attempts + 1;
        }

        if (!$state->save()) {
            Craft::error("Failed to save sync state for {$date}/{$facet}: " . json_encode($state->getErrors()), __METHOD__);
        }
    }

    /**
     * Fetches daily totals + breakdowns and persists them, tracking the `daily` and
     * `breakdowns` facets independently.
     *
     * Daily totals and breakdowns are fetched in one batch but can fail apart, so each is
     * marked on its own: totals succeeding lets the daily row (and the mirror-backed chart
     * and KPIs) land even when breakdowns errored, and the breakdowns facet is left to
     * retry. A day whose breakdowns errored entirely still saves its totals; the empty
     * `metrics` is refilled when the breakdowns facet retries. Days where both facets are
     * already done/exhausted are skipped before the provider is even called.
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

        $websiteId = Observatory::getInstance()->analytics->getStorageKey();
        if (empty($websiteId)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $todo = $this->_facetTodo($websiteId, $daySpecs, [self::FACET_DAILY, self::FACET_BREAKDOWNS]);
        if (empty($todo)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $plugin = Observatory::getInstance();
        $batch = $plugin->analytics->getDailyStatsAndBreakdownsBatch($todo, Observatory::MIRRORED_METRIC_TYPES);

        $total = \count($todo);
        $done = 0;
        $synced = 0;
        $failed = 0;
        foreach ($todo as $day) {
            $ds = $day['date'];
            $row = $batch[$ds] ?? null;
            $stats = $row['stats'] ?? null;
            $errors = $row['errors'] ?? [];
            $metrics = $row['metrics'] ?? [];

            if (empty($stats)) {
                // No totals → neither facet advanced; both retry.
                $this->_markFacet($websiteId, $ds, self::FACET_DAILY, false);
                $this->_markFacet($websiteId, $ds, self::FACET_BREAKDOWNS, false);
                $failed++;
                $reason = $errors ? implode('; ', $errors) : 'empty stats response';
                Craft::warning("fetchAndStoreDailyStats: failed for {$ds} — {$reason}", 'observatory');
            } elseif ($this->syncDailyStats($ds, $stats, $metrics)) {
                // Totals landed → daily done. When stats are present, the only errors the
                // batch reports are breakdown failures, so empty($errors) means breakdowns
                // are complete.
                $this->_markFacet($websiteId, $ds, self::FACET_DAILY, true);
                $breakdownsOk = empty($errors);
                $this->_markFacet($websiteId, $ds, self::FACET_BREAKDOWNS, $breakdownsOk);
                $synced++;
                if (!$breakdownsOk) {
                    Craft::warning("fetchAndStoreDailyStats: {$ds} totals saved; breakdowns incomplete (" . implode('; ', $errors) . ') — will retry.', 'observatory');
                } else {
                    Craft::debug("fetchAndStoreDailyStats: saved {$ds}.", 'observatory');
                }
            } else {
                $this->_markFacet($websiteId, $ds, self::FACET_DAILY, false);
                $this->_markFacet($websiteId, $ds, self::FACET_BREAKDOWNS, false);
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
     * Filters day specs down to those where at least one of the given facets still needs
     * work, so a fetch pass skips days already done/exhausted for its facet(s).
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $daySpecs
     * @param string[] $facets
     * @return array<int,array{date:string,startAt:int,endAt:int}>
     */
    private function _facetTodo(string $websiteId, array $daySpecs, array $facets): array
    {
        $dates = array_column($daySpecs, 'date');
        if (empty($dates)) {
            return [];
        }

        $states = $this->_loadFacetStates($websiteId, min($dates), max($dates), $facets);

        return array_values(array_filter($daySpecs, function(array $day) use ($states, $facets): bool {
            foreach ($facets as $facet) {
                if ($this->_facetNeedsWork($states["{$day['date']}|{$facet}"] ?? null)) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Fetches hourly pageviews and persists them, tracking the `hourly` facet.
     *
     * A day whose fetch errored is omitted by the source (key absent) → facet failed,
     * retried later. A present day with no rows is a real zero-traffic day → facet done,
     * no DB write. Days already done/exhausted for `hourly` are skipped up front.
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

        $websiteId = Observatory::getInstance()->analytics->getStorageKey();
        if (empty($websiteId)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $todo = $this->_facetTodo($websiteId, $daySpecs, [self::FACET_HOURLY]);
        if (empty($todo)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $plugin = Observatory::getInstance();
        $hourlyBatch = $plugin->analytics->getHourlyPageviewsBatch($todo);

        $total = \count($todo);
        $done = 0;
        $synced = 0;
        $failed = 0;
        foreach ($todo as $day) {
            $ds = $day['date'];

            if (!\array_key_exists($ds, $hourlyBatch)) {
                // Fetch errored for this day — retry on a later pass.
                $this->_markFacet($websiteId, $ds, self::FACET_HOURLY, false);
                $failed++;
            } elseif (empty($hourlyBatch[$ds])) {
                // Real zero-traffic day — nothing to store, but the facet is done.
                $this->_markFacet($websiteId, $ds, self::FACET_HOURLY, true);
            } elseif ($this->syncHourlyStats($ds, $hourlyBatch[$ds])) {
                $this->_markFacet($websiteId, $ds, self::FACET_HOURLY, true);
                $synced++;
            } else {
                $this->_markFacet($websiteId, $ds, self::FACET_HOURLY, false);
                $failed++;
            }

            $done++;
            if ($onProgress !== null) {
                $onProgress($done, $total);
            }
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * Fetches top events and refreshes the local rows, tracking the `events` facet.
     *
     * A failed fetch (key absent) leaves the day's existing rows intact and the facet
     * failed → retried later. A present day (including no events) is stored and done.
     * Days already done/exhausted for `events` are skipped up front.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $daySpecs
     * @return array{synced:int,failed:int}
     */
    public function fetchAndStoreEvents(array $daySpecs): array
    {
        if (empty($daySpecs)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $websiteId = Observatory::getInstance()->analytics->getStorageKey();
        if (empty($websiteId)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $todo = $this->_facetTodo($websiteId, $daySpecs, [self::FACET_EVENTS]);
        if (empty($todo)) {
            return ['synced' => 0, 'failed' => 0];
        }

        $plugin = Observatory::getInstance();
        $eventsBatch = $plugin->analytics->getEventsBatch($todo);

        $synced = 0;
        $failed = 0;
        foreach ($todo as $day) {
            $ds = $day['date'];
            if (!\array_key_exists($ds, $eventsBatch)) {
                $this->_markFacet($websiteId, $ds, self::FACET_EVENTS, false);
                $failed++;
                Craft::warning("fetchAndStoreEvents: events fetch failed for {$ds} — leaving existing rows intact.", 'observatory');
                continue;
            }
            if ($this->syncDailyEvents($ds, $eventsBatch[$ds])) {
                $this->_markFacet($websiteId, $ds, self::FACET_EVENTS, true);
                $synced++;
            } else {
                $this->_markFacet($websiteId, $ds, self::FACET_EVENTS, false);
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
                $name = $event['x'];
                if ($name === '') {
                    continue;
                }
                $rows[] = [
                    $websiteId,
                    $date,
                    $name,
                    (int) $event['y'],
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

    /**
     * Returns the cache key for a provider-requested auto-sync cooldown.
     */
    private function _autoSyncDeferredCacheKey(string $websiteId): string
    {
        return "observatory_autosync_deferred_{$websiteId}";
    }

    /**
     * Returns the cache key serializing sync job enqueue decisions.
     */
    private function _autoSyncEnqueueLockCacheKey(string $websiteId): string
    {
        return "observatory_autosync_enqueue_lock_{$websiteId}";
    }

    /**
     * Returns fixed-size backfill chunks not covered by an existing pending window.
     *
     * @return array<int,array{0:int,1:int}>
     */
    private function _backfillChunks(int $days, int $coveredDays): array
    {
        $chunks = [];
        $firstUncovered = max(Observatory::EVENTS_CLOSED_DAYS + 1, $coveredDays + 1);

        for ($start = $firstUncovered; $start <= $days; $start += SyncDailyStatsJob::BATCH_SIZE) {
            $chunks[] = [$start, min($start + SyncDailyStatsJob::BATCH_SIZE - 1, $days)];
        }

        return $chunks;
    }
}

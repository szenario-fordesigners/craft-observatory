<?php

namespace szenario\craftobservatory\jobs;

use Craft;
use craft\queue\BaseJob;
use szenario\craftobservatory\Observatory;

/**
 * Backfills a chunk of older days that haven't been fetched yet (daily + hourly).
 *
 * One job covers the closed range of day offsets [startOffset, endOffset]. The
 * coordinator splits a large window into BATCH_SIZE-day chunks and queues them all up
 * front, so no single job processes enough days to risk a queue timeout. Each day is
 * fetched exactly once — days that already have a DailyStats row are skipped — so
 * re-running a chunk is a cheap no-op once its days are present. Events are not fetched
 * here; the events widget only covers the recent window handled by {@see SyncRecentDaysJob}.
 */
class SyncDailyStatsJob extends BaseJob
{
    /** Maximum number of days a single batch job processes. */
    public const BATCH_SIZE = 50;

    public string $websiteId = '';
    public int $startOffset = 1;
    public int $endOffset = 1;

    public function execute($queue): void
    {
        // Release the PHP session lock the web-based queue runner holds while
        // this job runs — otherwise parallel widget AJAX requests on the dashboard
        // block on session_start() for the full sync duration.
        if (Craft::$app->getRequest()->getIsWebRequest()) {
            Craft::$app->getSession()->close();
        }

        $startTime = microtime(true);
        Craft::info(
            "SyncDailyStatsJob starting: websiteId={$this->websiteId}, offsets={$this->startOffset}..{$this->endOffset}.",
            'observatory'
        );

        $plugin = Observatory::getInstance();

        try {
            $daySpecs = $plugin->sync->findUnsyncedDaySpecs($this->websiteId, $this->startOffset, $this->endOffset);

            if (empty($daySpecs)) {
                Craft::info('SyncDailyStatsJob: all days in chunk already synced.', 'observatory');
                return;
            }

            Craft::info('SyncDailyStatsJob: fetching ' . \count($daySpecs) . ' unsynced day(s).', 'observatory');

            $daily = $plugin->sync->fetchAndStoreDailyStats(
                $daySpecs,
                fn(int $done, int $total) => $this->setProgress($queue, $done / $total * 0.5)
            );

            $hourly = $plugin->sync->fetchAndStoreHourlyStats(
                $daySpecs,
                fn(int $done, int $total) => $this->setProgress($queue, 0.5 + $done / $total * 0.5)
            );

            $elapsed = number_format(microtime(true) - $startTime, 2);
            Craft::info(
                "SyncDailyStatsJob finished: daily(synced={$daily['synced']}, failed={$daily['failed']}), " .
                "hourly(synced={$hourly['synced']}, failed={$hourly['failed']}), elapsed={$elapsed}s.",
                'observatory'
            );
        } finally {
            $plugin->sync->resetAutoSyncTimeGuard($this->websiteId);
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('observatory', 'Backfilling missing analytics daily stats');
    }
}

<?php

namespace szenario\craftobservatory\jobs;

use Craft;
use craft\queue\BaseJob;
use szenario\craftobservatory\exceptions\AnalyticsRateLimitedException;
use szenario\craftobservatory\Observatory;
use szenario\craftobservatory\services\SyncCoordinator;

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
        $rateLimited = false;

        try {
            // Backfill covers daily + breakdowns + hourly, but never events (the events
            // widget only shows the recent window), so the events facet is excluded — else
            // old days would look perpetually unsynced.
            $daySpecs = $plugin->sync->findUnsyncedDaySpecs($this->websiteId, $this->startOffset, $this->endOffset, [
                SyncCoordinator::FACET_DAILY,
                SyncCoordinator::FACET_BREAKDOWNS,
                SyncCoordinator::FACET_HOURLY,
            ]);

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

            // fetchAndStoreDailyStats()/fetchAndStoreHourlyStats() swallow per-day failures into
            // these counts rather than throwing, so a broken connection (e.g. revoked credentials)
            // would otherwise log "failed=N" forever while every job still reports success. Throw
            // so Craft's queue marks the job failed and it actually shows up in the Queue Manager.
            $failed = $daily['failed'] + $hourly['failed'];
            if ($failed > 0) {
                throw new \RuntimeException("SyncDailyStatsJob: {$failed} day-facet fetch(es) failed — see the Observatory logs.");
            }
        } catch (AnalyticsRateLimitedException $e) {
            $rateLimited = true;
            $plugin->sync->deferAutoSync($this->websiteId, $e->retryAfterSeconds);
            Craft::warning("SyncDailyStatsJob deferred for {$e->retryAfterSeconds}s by PostHog rate limit.", 'observatory');
        } finally {
            if (!$rateLimited) {
                $plugin->sync->resetAutoSyncTimeGuard($this->websiteId);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('observatory', 'Backfilling missing analytics daily stats');
    }
}

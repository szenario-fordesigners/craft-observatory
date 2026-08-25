<?php

namespace szenario\craftobservatory\jobs;

use Craft;
use craft\queue\BaseJob;
use szenario\craftobservatory\exceptions\AnalyticsRateLimitedException;
use szenario\craftobservatory\Observatory;

/**
 * Fetches any not-yet-synced days in the rolling recent window — the last
 * Observatory::EVENTS_CLOSED_DAYS closed days, which together with today (handled live)
 * make up the dashboard's "last week".
 *
 * Each day is fetched exactly once: a day with a DailyStats row is skipped entirely.
 * Unlike the older backfill, the recent window also fetches events, since the events
 * widget only ever shows this window. Bounded at a handful of days, so it never
 * approaches a job timeout; older days are handled in chunks by {@see SyncDailyStatsJob}.
 */
class SyncRecentDaysJob extends BaseJob
{
    public string $websiteId = '';

    public function execute($queue): void
    {
        // Release the PHP session lock the web-based queue runner holds while
        // this job runs — otherwise parallel widget AJAX requests on the dashboard
        // block on session_start() for the full sync duration.
        if (Craft::$app->getRequest()->getIsWebRequest()) {
            Craft::$app->getSession()->close();
        }

        $startTime = microtime(true);
        Craft::info("SyncRecentDaysJob starting: websiteId={$this->websiteId}.", 'observatory');

        $plugin = Observatory::getInstance();
        $rateLimited = false;

        try {
            $daySpecs = $plugin->sync->findUnsyncedDaySpecs($this->websiteId, 1, Observatory::EVENTS_CLOSED_DAYS);

            if (empty($daySpecs)) {
                Craft::info('SyncRecentDaysJob: recent window already synced.', 'observatory');
                return;
            }

            Craft::info('SyncRecentDaysJob: fetching ' . \count($daySpecs) . ' unsynced recent day(s).', 'observatory');

            // Three passes over the same unsynced days, each a third of the progress bar.
            $daily = $plugin->sync->fetchAndStoreDailyStats(
                $daySpecs,
                fn(int $done, int $total) => $this->setProgress($queue, $done / $total / 3)
            );

            $hourly = $plugin->sync->fetchAndStoreHourlyStats(
                $daySpecs,
                fn(int $done, int $total) => $this->setProgress($queue, 1 / 3 + $done / $total / 3)
            );

            $events = $plugin->sync->fetchAndStoreEvents($daySpecs);
            $this->setProgress($queue, 1.0);

            $elapsed = number_format(microtime(true) - $startTime, 2);
            Craft::info(
                "SyncRecentDaysJob finished: daily(synced={$daily['synced']}, failed={$daily['failed']}), " .
                "hourly(synced={$hourly['synced']}, failed={$hourly['failed']}), " .
                "events(synced={$events['synced']}, failed={$events['failed']}), days=" . \count($daySpecs) .
                ", elapsed={$elapsed}s.",
                'observatory'
            );

            // fetchAndStore*() swallow per-day failures into these counts rather than throwing, so
            // a broken connection would otherwise log "failed=N" forever while every job still
            // reports success. Throw so Craft's queue marks the job failed and it actually shows
            // up in the Queue Manager.
            $failed = $daily['failed'] + $hourly['failed'] + $events['failed'];
            if ($failed > 0) {
                throw new \RuntimeException("SyncRecentDaysJob: {$failed} day-facet fetch(es) failed — see the Observatory logs.");
            }
        } catch (AnalyticsRateLimitedException $e) {
            $rateLimited = true;
            $plugin->sync->deferAutoSync($this->websiteId, $e->retryAfterSeconds);
            Craft::warning("SyncRecentDaysJob deferred for {$e->retryAfterSeconds}s by PostHog rate limit.", 'observatory');
        } finally {
            if (!$rateLimited) {
                $plugin->sync->resetAutoSyncTimeGuard($this->websiteId);
            }
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('observatory', 'Syncing recent analytics stats');
    }
}

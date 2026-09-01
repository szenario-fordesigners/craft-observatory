<?php

namespace szenario\craftobservatory\console\controllers;

use Craft;
use craft\console\Controller;
use szenario\craftobservatory\exceptions\AnalyticsRateLimitedException;
use szenario\craftobservatory\Observatory;
use szenario\craftobservatory\services\SyncCoordinator;
use yii\console\ExitCode;

/**
 * Sync controller for analytics data.
 */
class SyncController extends Controller
{
    /**
     * @var bool Refetch days the coordinator already considers finished, instead of skipping them.
     */
    public bool $force = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['force']);
    }

    /**
     * Pulls closed days from the selected analytics source into the local mirror.
     *
     * Runs the same fetch routines the queue jobs use, so the CLI and the background sync can
     * never disagree about what a synced day contains. Days already recorded as finished are
     * skipped unless --force is passed. Today is never fetched — the live widget queries cover it.
     *
     * Optional as a cron entry point (keeps the mirror warm so the CP never waits on a cold
     * backfill), and the recovery path when stored data needs rebuilding.
     *
     * Example: `php craft observatory/sync 90 --force`
     *
     * @param int $days How many closed days back to cover.
     * @return int
     */
    public function actionIndex(int $days = 30): int
    {
        $plugin = Observatory::getInstance();
        $websiteId = $plugin->analytics->getStorageKey();

        if (empty($websiteId)) {
            $this->stderr("No analytics source configured — add the provider credentials in Settings → Plugins → Observatory.\n");
            return ExitCode::CONFIG;
        }

        $days = min(SyncCoordinator::MAX_SYNC_DAYS, max(1, $days));

        // Two overlapping invocations for the same connection (a slow manual run colliding with
        // a cron, or the command run twice by hand) would otherwise race on the same
        // (websiteId, date) rows and can throw an unhandled unique-constraint error mid-sync.
        $mutex = Craft::$app->getMutex();
        $lockName = "observatory-sync-{$websiteId}";
        if (!$mutex->acquire($lockName)) {
            $this->stderr("Another sync is already running for this connection — skipping.\n");
            return ExitCode::TEMPFAIL;
        }

        try {
            if ($this->force) {
                $forgotten = $plugin->sync->forgetSyncState($websiteId, 1, $days);
                $this->stdout("Forgot {$forgotten} sync-state row(s).\n");
                // ponytail: provider responses stay cached for up to 24h, so a --force inside that
                // window can refetch straight from cache. Tag the PostHog query cache with a
                // TagDependency if --force ever needs to guarantee a round trip.
                $this->stdout("Cached provider responses (up to 24h) may be reused — run `php craft clear-caches/data` first to force a round trip.\n");
            }

            // The facet subsets must match what this command actually fetches, or days look
            // perpetually unsynced. Events cover only the recent window, matching the events
            // widget, so asking for them over the whole range would never come up clean.
            $statSpecs = $plugin->sync->findUnsyncedDaySpecs($websiteId, 1, $days, [
                SyncCoordinator::FACET_DAILY,
                SyncCoordinator::FACET_BREAKDOWNS,
                SyncCoordinator::FACET_HOURLY,
            ]);
            $eventSpecs = $plugin->sync->findUnsyncedDaySpecs($websiteId, 1, min($days, Observatory::EVENTS_CLOSED_DAYS), [
                SyncCoordinator::FACET_EVENTS,
            ]);

            if (empty($statSpecs) && empty($eventSpecs)) {
                $this->stdout("Nothing to sync — the last {$days} closed day(s) are already complete.\n");
                return ExitCode::OK;
            }

            $this->stdout('Syncing ' . \count($statSpecs) . ' day(s), plus events for ' . \count($eventSpecs) . " day(s)...\n");

            try {
                $daily = $plugin->sync->fetchAndStoreDailyStats($statSpecs);
                $hourly = $plugin->sync->fetchAndStoreHourlyStats($statSpecs);
                $events = $plugin->sync->fetchAndStoreEvents($eventSpecs);
            } catch (AnalyticsRateLimitedException $e) {
                $this->stderr("Stopped: provider rate limit active, retry in {$e->retryAfterSeconds}s.\n");
                return ExitCode::TEMPFAIL;
            }

            foreach (['daily' => $daily, 'hourly' => $hourly, 'events' => $events] as $facet => $result) {
                $this->stdout("  {$facet}: synced={$result['synced']} failed={$result['failed']}\n");
            }

            $failed = $daily['failed'] + $hourly['failed'] + $events['failed'];

            if ($failed > 0) {
                $this->stderr("{$failed} fetch(es) failed — see the Observatory logs. Retries are capped at " . SyncCoordinator::SYNC_MAX_ATTEMPTS . " attempts per facet.\n");
                return ExitCode::UNSPECIFIED_ERROR;
            }

            $this->stdout("Done.\n");
            return ExitCode::OK;
        } finally {
            $mutex->release($lockName);
        }
    }
}

<?php

namespace szenario\craftobservatory\controllers;

use craft\web\Controller;
use szenario\craftobservatory\helpers\AnalyticsTime;
use szenario\craftobservatory\records\DailyEvents;
use szenario\craftobservatory\services\SyncCoordinator;
use szenario\craftobservatory\Observatory;
use yii\web\Response;

class DashboardController extends Controller
{
    private const DEFAULT_METRIC_TYPES = ['url', 'entry', 'exit', 'referrer', 'channel', 'browser', 'os', 'device', 'country', 'region', 'city'];
    private const ALLOWED_PAGEVIEW_UNITS = ['hour', 'day', 'month', 'year'];
    private const MAX_CP_FRESHNESS_DAYS = 366;

    /**
     * Returns a 7×24 heatmap of average visitor counts by weekday and hour of day.
     * Accepts an optional `days` query param (default 90) controlling the lookback window.
     */
    public function actionGetHeatmapData(): Response
    {
        $request = \Craft::$app->getRequest();
        $days = max(1, (int) $request->getParam('days', 90));

        \Craft::$app->getSession()->close();

        $plugin = Observatory::getInstance();
        $plugin->sync->autoSyncMissingDays($days);

        // Any unsynced closed day in the window means a sync job is still pending —
        // signal the client so it can poll until the historical mirror is complete.
        $websiteId = $plugin->analytics->getStorageKey();
        // The heatmap is built from the daily + hourly mirror (events don't apply over this
        // window), so its "still syncing" flag tracks only those facets.
        $syncing = !empty($websiteId)
            && !empty($plugin->sync->findUnsyncedDaySpecs($websiteId, 1, $days, [
                SyncCoordinator::FACET_DAILY,
                SyncCoordinator::FACET_HOURLY,
            ]));

        return $this->asJson($plugin->stats->getHeatmapData($days) + [
            '_syncing' => $syncing,
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Compact 7-day summary for the dashboard widget.
     */
    public function actionGetWidgetSummary(): Response
    {
        \Craft::$app->getSession()->close();
        $plugin = Observatory::getInstance();
        $plugin->sync->autoSyncMissingDays();

        return $this->asJson($plugin->stats->getWidgetSummary() + [
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Compact 7-day usage summary (views + average visit duration) for the dashboard widget.
     */
    public function actionGetUsageSummary(): Response
    {
        \Craft::$app->getSession()->close();
        $plugin = Observatory::getInstance();
        $plugin->sync->autoSyncMissingDays();

        return $this->asJson($plugin->stats->getUsageSummary() + [
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Current number of active visitors for the live-visitors widget.
     *
     * Backed by the configured analytics source (cached 60s upstream), so polling this
     * once a minute from the client stays within one cache window.
     */
    public function actionGetActiveVisitors(): Response
    {
        \Craft::$app->getSession()->close();
        $plugin = Observatory::getInstance();

        return $this->asJson([
            'visitors' => $plugin->analytics->getLiveVisitors() ?? 0,
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Get all dashboard widget data in one request.
     *
     * @return Response|null
     */
    public function actionGetDashboardData(): ?Response
    {
        $request = \Craft::$app->getRequest();
        [$startAt, $endAt] = $this->resolveRange();
        $unit = $this->normalizePageviewUnit($request->getParam('unit'));
        if ($unit === null) {
            return $this->asFailure('Invalid pageview unit', ['error' => 'Invalid pageview unit']);
        }

        $includePageviews = (string) $request->getParam('includePageviews', '1') !== '0';
        $includeStats = (string) $request->getParam('includeStats', '1') !== '0';

        \Craft::$app->getSession()->close();
        $plugin = Observatory::getInstance();
        $closedDayOffsets = $this->closedDayOffsetsForRange($startAt, $endAt);
        $plugin->sync->autoSyncMissingDays($closedDayOffsets[1] ?? 30);

        // Breakdowns are served from the local mirror (closed days) + today live; totals
        // stay live, since unique-visitor counts can't be summed per-day.
        $metrics = $plugin->stats->getRangeBreakdowns(self::DEFAULT_METRIC_TYPES, $startAt, $endAt);
        $freshness = $this->dashboardFreshnessEnvelope($plugin, $closedDayOffsets, $unit, $includePageviews);

        return $this->asJson([
            'pageviews' => $includePageviews ? $plugin->stats->getRangePageviews($startAt, $endAt, $unit) : null,
            'stats' => $includeStats ? $plugin->analytics->getTotals($startAt, $endAt) : null,
            'metrics' => $metrics,
            '_syncing' => $freshness['_syncing'],
            'lastSyncedAt' => $freshness['lastSyncedAt'],
            'missingDays' => $freshness['missingDays'],
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Get updated pageviews for the dashboard widget via AJAX.
     *
     * @return Response|null
     */
    public function actionGetPageviews(): ?Response
    {
        $request = \Craft::$app->getRequest();
        [$startAt, $endAt] = $this->resolveRange();
        $unit = $this->normalizePageviewUnit($request->getParam('unit'));
        if ($unit === null) {
            return $this->asFailure('Invalid pageview unit', ['error' => 'Invalid pageview unit']);
        }

        \Craft::$app->getSession()->close();
        $plugin = Observatory::getInstance();
        $plugin->sync->autoSyncMissingDays();
        $pageviews = $plugin->stats->getRangePageviews($startAt, $endAt, $unit);

        return $this->asJson($pageviews);
    }
    /**
     * Get statistics (visitors, visits, pageviews, etc) for the dashboard widget via AJAX.
     *
     * @return Response
     */
    public function actionGetStats(): Response
    {
        [$startAt, $endAt] = $this->resolveRange();

        \Craft::$app->getSession()->close();
        $stats = Observatory::getInstance()->analytics->getTotals(
            $startAt,
            $endAt
        );

        if ($stats === null) {
            return $this->asJson([
                'error' => 'Analytics provider is temporarily unavailable.',
                'temporary' => true,
            ])->setStatusCode(503);
        }

        return $this->asJson($stats);
    }

    /**
     * Get specific metrics for the dashboard widget via AJAX.
     *
     * @return Response|null
     */
    public function actionGetMetrics(): ?Response
    {
        $request = \Craft::$app->getRequest();
        [$startAt, $endAt] = $this->resolveRange();
        $typeParam = $request->getParam('type');
        $typesParams = $request->getParam('types');

        if (!$typeParam && !$typesParams) {
            return $this->asFailure('Missing metric type(s)', ['error' => 'Missing metric type(s)']);
        }

        \Craft::$app->getSession()->close();

        $plugin = Observatory::getInstance();
        $plugin->sync->autoSyncMissingDays();

        if ($typesParams) {
            $types = $this->normalizeMetricTypes(explode(',', (string) $typesParams));
            if (empty($types)) {
                return $this->asFailure('Invalid metric type(s)', ['error' => 'Invalid metric type(s)']);
            }

            return $this->asJson($plugin->stats->getRangeBreakdowns($types, $startAt, $endAt));
        }

        $types = $this->normalizeMetricTypes([(string) $typeParam]);
        if (empty($types)) {
            return $this->asFailure('Invalid metric type', ['error' => 'Invalid metric type']);
        }

        $metrics = $plugin->stats->getRangeBreakdown($types[0], $startAt, $endAt);

        return $this->asJson([
            'data' => $metrics,
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Top 5 events over a rolling window: Observatory::EVENTS_CLOSED_DAYS closed days from
     * the local mirror plus today's counts fetched live, so totals stay current within the day.
     */
    public function actionGetTopEvents(): Response
    {
        \Craft::$app->getSession()->close();

        $plugin = Observatory::getInstance();
        $plugin->sync->autoSyncMissingDays();

        $websiteId = $plugin->analytics->getStorageKey();

        if (empty($websiteId)) {
            return $this->asJson([
                'data' => [],
                '_status' => $plugin->analytics->getStatus(),
            ]);
        }

        $closedDays = Observatory::EVENTS_CLOSED_DAYS;

        $closedRows = DailyEvents::find()
            ->select(['eventName AS x', 'SUM(total) AS y'])
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', date('Y-m-d', strtotime("-{$closedDays} days"))])
            ->andWhere(['<', 'date', date('Y-m-d')])
            ->groupBy('eventName')
            ->asArray()
            ->all();

        $todayStart = strtotime('today') * 1000;
        // Bucket "now" to the minute so the underlying getMetrics cache key is stable
        // for 60s — otherwise the per-second key bypasses caching entirely.
        $now = (int) (floor(time() / 60) * 60) * 1000;
        $todayRows = $plugin->analytics->getBreakdown($todayStart, $now, 'event') ?? [];

        $totals = [];
        foreach ($closedRows as $row) {
            $totals[(string) $row['x']] = (int) $row['y'];
        }
        // Defensive: /metrics is upstream and untyped, so skip rows whose shape
        // doesn't match {x: string, y: numeric}. A nested object or missing key
        // would otherwise silently corrupt totals via PHP's loose casts.
        foreach ($todayRows as $row) {
            if (!\is_array($row) || !isset($row['x'], $row['y']) || !\is_string($row['x']) || !\is_numeric($row['y'])) {
                continue;
            }
            $name = $row['x'];
            if ($name === '') {
                continue;
            }
            $totals[$name] = ($totals[$name] ?? 0) + (int) $row['y'];
        }

        arsort($totals);
        $data = [];
        foreach (array_slice($totals, 0, 5, true) as $name => $count) {
            $data[] = ['x' => $name, 'y' => $count];
        }

        // Any unsynced day inside the events window means the closed-days half of
        // the totals is still incomplete — flag the client so it polls until done.
        $syncing = !empty($plugin->sync->findUnsyncedDaySpecs($websiteId, 1, $closedDays, [
            SyncCoordinator::FACET_EVENTS,
        ]));

        return $this->asJson([
            'data' => $data,
            '_syncing' => $syncing,
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Cache-friendly bucket size for "now"-relative query windows, in seconds.
     */
    private const RANGE_BUCKET_SECONDS = 60;

    /**
     * Resolves the [startAt, endAt] window (in ms) for a request.
     *
     * endAt is capped at a {@see self::RANGE_BUCKET_SECONDS}-second boundary so that
     * "now"-relative queries (today, last 7 days, …) share a stable cache key within
     * each bucket instead of producing a unique per-second/per-ms key on every render.
     * An explicit endAt that already lies in the past passes through unchanged, so
     * historical ranges are never truncated. The default 7-day startAt is bucketed for
     * the same reason; an explicit startAt (always a day boundary from the UI) is left
     * as-is.
     *
     * @return array{0:int,1:int}
     */
    private function resolveRange(): array
    {
        $request = \Craft::$app->getRequest();
        $bucketedNow = $this->bucketMs(time() * 1000);

        $startAtParam = $request->getParam('startAt');
        $startAt = $startAtParam !== null
            ? (int) $startAtParam
            : $this->bucketMs(strtotime('-7 days') * 1000);

        $endAtParam = $request->getParam('endAt');
        $endAt = $endAtParam !== null ? (int) $endAtParam : $bucketedNow;

        return [$startAt, min($endAt, $bucketedNow)];
    }

    /**
     * Floors a millisecond timestamp down to a {@see self::RANGE_BUCKET_SECONDS} boundary.
     */
    private function bucketMs(int $ms): int
    {
        $bucketMs = self::RANGE_BUCKET_SECONDS * 1000;

        return intdiv($ms, $bucketMs) * $bucketMs;
    }

    /**
     * Converts a timestamp range into closed-day offsets from today.
     *
     * Unbounded or malformed ranges could enqueue decades of work on old sites. Cap
     * freshness/sync coverage to a practical one-year window for that case; explicit
     * finite ranges keep their actual span.
     *
     * @return array{0:int,1:int}|null [nearest closed-day offset, furthest closed-day offset]
     */
    private function closedDayOffsetsForRange(int $startAt, int $endAt): ?array
    {
        $tz = AnalyticsTime::appTimeZone();
        $today = new \DateTimeImmutable('today', $tz);
        $yesterday = $today->modify('-1 day');

        $startDate = (new \DateTimeImmutable('@' . intdiv($startAt, 1000)))->setTimezone($tz)->setTime(0, 0);
        $endDate = (new \DateTimeImmutable('@' . intdiv($endAt, 1000)))->setTimezone($tz)->setTime(0, 0);

        if ($startAt <= 0) {
            $startDate = $today->modify('-' . self::MAX_CP_FRESHNESS_DAYS . ' days');
        }

        if ($endDate > $yesterday) {
            $endDate = $yesterday;
        }

        if ($startDate > $endDate) {
            return null;
        }

        $nearestOffset = max(1, (int) $endDate->diff($today)->days);
        $furthestOffset = max(1, (int) $startDate->diff($today)->days);

        if ($nearestOffset > self::MAX_CP_FRESHNESS_DAYS) {
            return null;
        }

        return [$nearestOffset, min($furthestOffset, self::MAX_CP_FRESHNESS_DAYS)];
    }

    /**
     * @param array{0:int,1:int}|null $closedDayOffsets
     * @return array{_syncing:bool,lastSyncedAt:?string,missingDays:array<int,string>}
     */
    private function dashboardFreshnessEnvelope(Observatory $plugin, ?array $closedDayOffsets, string $unit, bool $includePageviews): array
    {
        $empty = ['_syncing' => false, 'lastSyncedAt' => null, 'missingDays' => []];
        $websiteId = $plugin->analytics->getStorageKey();

        if (empty($websiteId) || $closedDayOffsets === null) {
            return $empty;
        }

        $facets = [SyncCoordinator::FACET_BREAKDOWNS];
        if ($includePageviews) {
            $facets[] = $unit === 'hour' ? SyncCoordinator::FACET_HOURLY : SyncCoordinator::FACET_DAILY;
        }

        return $plugin->sync->getFreshnessForOffsets(
            $websiteId,
            $closedDayOffsets[0],
            $closedDayOffsets[1],
            array_values(array_unique($facets)),
        );
    }

    private function normalizePageviewUnit(mixed $unit): ?string
    {
        if (!\is_string($unit)) {
            return null;
        }

        $unit = trim($unit);

        return \in_array($unit, self::ALLOWED_PAGEVIEW_UNITS, true) ? $unit : null;
    }

    /**
     * @param string[] $types
     * @return string[]
     */
    private function normalizeMetricTypes(array $types): array
    {
        $types = array_map('trim', $types);
        $types = array_filter($types, fn(string $type) => \in_array($type, self::DEFAULT_METRIC_TYPES, true));

        return array_values(array_unique($types));
    }
}

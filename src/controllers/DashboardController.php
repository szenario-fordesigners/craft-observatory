<?php

namespace szenario\craftumamiis\controllers;

use craft\web\Controller;
use szenario\craftumamiis\records\DailyEvents;
use szenario\craftumamiis\UmamiIs;
use yii\web\Response;

class DashboardController extends Controller
{
    private const DEFAULT_METRIC_TYPES = ['url', 'entry', 'exit', 'referrer', 'channel', 'browser', 'os', 'device', 'country', 'region', 'city'];
    private const ALLOWED_PAGEVIEW_UNITS = ['hour', 'day', 'month', 'year'];

    /**
     * Returns a 7×24 heatmap of average visitor counts by weekday and hour of day.
     * Accepts an optional `days` query param (default 90) controlling the lookback window.
     */
    public function actionGetHeatmapData(): Response
    {
        $request = \Craft::$app->getRequest();
        $days = max(1, (int) $request->getParam('days', 90));

        \Craft::$app->getSession()->close();

        $plugin = UmamiIs::getInstance();
        $plugin->sync->autoSyncMissingDays($days);

        // Any unsynced closed day in the window means a sync job is still pending —
        // signal the client so it can poll until the historical mirror is complete.
        $websiteId = $plugin->analytics->getStorageKey();
        $syncing = !empty($websiteId)
            && !empty($plugin->sync->findUnsyncedDaySpecs($websiteId, 1, $days));

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
        $plugin = UmamiIs::getInstance();
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
        $plugin = UmamiIs::getInstance();
        $plugin->sync->autoSyncMissingDays();

        return $this->asJson($plugin->stats->getUsageSummary() + [
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Current number of active visitors for the live-visitors widget.
     *
     * Backed by Umami's /active endpoint (cached 60s upstream), so polling this
     * once a minute from the client stays within one cache window.
     */
    public function actionGetActiveVisitors(): Response
    {
        \Craft::$app->getSession()->close();
        $plugin = UmamiIs::getInstance();

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

        \Craft::$app->getSession()->close();
        $plugin = UmamiIs::getInstance();
        $metrics = $plugin->analytics->getBreakdowns($startAt, $endAt, self::DEFAULT_METRIC_TYPES);

        return $this->asJson([
            'pageviews' => $includePageviews ? $plugin->analytics->getPageviews($startAt, $endAt, $unit) : null,
            'stats' => $plugin->analytics->getTotals($startAt, $endAt),
            'metrics' => $metrics,
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
        $pageviews = UmamiIs::getInstance()->analytics->getPageviews(
            $startAt,
            $endAt,
            $unit
        );

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
        $stats = UmamiIs::getInstance()->analytics->getTotals(
            $startAt,
            $endAt
        );

        return $this->asJson($stats ?? []);
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

        if ($typesParams) {
            $types = $this->normalizeMetricTypes(explode(',', (string) $typesParams));
            if (empty($types)) {
                return $this->asFailure('Invalid metric type(s)', ['error' => 'Invalid metric type(s)']);
            }

            return $this->asJson(UmamiIs::getInstance()->analytics->getBreakdowns($startAt, $endAt, $types));
        }

        $types = $this->normalizeMetricTypes([(string) $typeParam]);
        if (empty($types)) {
            return $this->asFailure('Invalid metric type', ['error' => 'Invalid metric type']);
        }

        $plugin = UmamiIs::getInstance();
        $metrics = $plugin->analytics->getBreakdown(
            $startAt,
            $endAt,
            $types[0]
        );

        return $this->asJson([
            'data' => $metrics ?? [],
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }

    /**
     * Top 5 events over a rolling window: UmamiIs::EVENTS_CLOSED_DAYS closed days from
     * the local mirror plus today's counts fetched live, so totals stay current within the day.
     */
    public function actionGetTopEvents(): Response
    {
        \Craft::$app->getSession()->close();

        $plugin = UmamiIs::getInstance();
        $plugin->sync->autoSyncMissingDays();

        $websiteId = $plugin->analytics->getStorageKey();

        if (empty($websiteId)) {
            return $this->asJson([
                'data' => [],
                '_status' => $plugin->analytics->getStatus(),
            ]);
        }

        $closedDays = UmamiIs::EVENTS_CLOSED_DAYS;

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
        $syncing = !empty($plugin->sync->findUnsyncedDaySpecs($websiteId, 1, $closedDays));

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

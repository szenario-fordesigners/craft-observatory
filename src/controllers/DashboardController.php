<?php

namespace szenario\craftobservatory\controllers;

use craft\web\Controller;
use szenario\craftobservatory\helpers\AnalyticsTime;
use szenario\craftobservatory\Observatory;
use szenario\craftobservatory\records\DailyEvents;
use szenario\craftobservatory\services\SyncCoordinator;
use yii\web\Response;

class DashboardController extends Controller
{
    private const DEFAULT_METRIC_TYPES = ['url', 'entry', 'exit', 'referrer', 'channel', 'browser', 'os', 'device', 'country', 'region', 'city'];
    private const MAX_CP_FRESHNESS_DAYS = 366;

    /**
     * Window used when a request names no range — including the dashboard widgets, which
     * request metrics without one.
     */
    private const DEFAULT_RANGE = '7d';

    /**
     * @inheritdoc
     *
     * Craft already gates the CP section itself and the nav item behind this permission, but it
     * skips the check for action requests ({@see \craft\web\Application::handleRequest()}), so
     * every endpoint here has to make it too — otherwise any user with CP access can read the
     * full analytics by calling these directly, and via the dashboard widgets, which render
     * outside the plugin's own URL segment.
     *
     * The permission is registered by Craft for any plugin with a CP section, so there is
     * nothing to declare. Admins and Solo installs pass automatically
     * ({@see \craft\elements\User::can()}).
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('accessPlugin-' . Observatory::getInstance()->id);

        return true;
    }

    /**
     * Returns a 7×24 heatmap of average visitor counts by weekday and hour of day.
     * Accepts an optional `days` query param (default 90, capped at
     * {@see self::MAX_CP_FRESHNESS_DAYS}) controlling the lookback window — uncapped, this
     * request-supplied value would drive an unbounded number of queued backfill jobs.
     */
    public function actionGetHeatmapData(): Response
    {
        $request = \Craft::$app->getRequest();
        $days = min(self::MAX_CP_FRESHNESS_DAYS, max(1, (int) $request->getParam('days', 90)));

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
        [$startAt, $endAt, $unit, $compareStartAt] = $this->resolveRange();

        $includePageviews = $this->stringParam('includePageviews', '1') !== '0';
        $includeStats = $this->stringParam('includeStats', '1') !== '0';

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
            'stats' => $includeStats ? $this->totalsWithComparison($startAt, $endAt, $compareStartAt) : null,
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
        [$startAt, $endAt, $unit] = $this->resolveRange();

        \Craft::$app->getSession()->close();
        $plugin = Observatory::getInstance();
        $plugin->sync->autoSyncMissingDays();
        $pageviews = $plugin->stats->getRangePageviews($startAt, $endAt, $unit);

        return $this->asJson(($pageviews ?? []) + [
            '_status' => $plugin->analytics->getStatus(),
        ]);
    }
    /**
     * Get statistics (visitors, visits, pageviews, etc) for the dashboard widget via AJAX.
     *
     * @return Response
     */
    public function actionGetStats(): Response
    {
        [$startAt, $endAt, , $compareStartAt] = $this->resolveRange();

        \Craft::$app->getSession()->close();
        $stats = $this->totalsWithComparison($startAt, $endAt, $compareStartAt);

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

            return $this->asJson([
                'data' => $plugin->stats->getRangeBreakdowns($types, $startAt, $endAt),
                '_status' => $plugin->analytics->getStatus(),
            ]);
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

        // Dates come from AnalyticsTime, never date()/strtotime(): those read PHP's default
        // zone, which Craft sets to the *viewing user's* personal timezone on CP requests. The
        // stored `date` column is always in the system zone, so a user zone ahead of it would
        // silently drop the first hours of the day out of the live half of these totals.
        $todayStr = AnalyticsTime::dateOffset(0);

        $closedRows = DailyEvents::find()
            ->select(['eventName AS x', 'SUM(total) AS y'])
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', AnalyticsTime::dateOffset($closedDays)])
            ->andWhere(['<', 'date', $todayStr])
            ->groupBy('eventName')
            ->asArray()
            ->all();

        [$todayStart] = AnalyticsTime::dayBounds($todayStr);
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
        // would otherwise silently corrupt totals via PHP's loose casts. PHPStan
        // trusts getBreakdown()'s PHPDoc'd return shape as guaranteed and flags this
        // whole check as dead code, but that shape isn't runtime-enforced against the
        // actual upstream response — this check is what makes it true.
        foreach ($todayRows as $row) {
            // @phpstan-ignore-next-line booleanNot.alwaysFalse, isset.offset, booleanOr.alwaysFalse
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
     * Resolves the [startAt, endAt, unit] window (ms) for a request.
     *
     * The client names a range (`range=7d`, or `range=custom` with `startDate`/`endDate` as
     * Y-m-d) and the boundaries are derived here, in the site's timezone. It deliberately does
     * not accept raw startAt/endAt timestamps: those were computed from the browser clock, so
     * a CP session in another zone selected a shifted window and then read the wrong day keys
     * out of the mirror, which is bucketed in {@see AnalyticsTime::appTimeZone()}. The unit
     * comes back with the window for the same reason — the two are one decision, and splitting
     * them across the wire is what let them disagree.
     *
     * @return array{0:int,1:int,2:string,3:int}
     */
    private function resolveRange(): array
    {
        $range = $this->stringParam('range', self::DEFAULT_RANGE);

        $resolved = $range === 'custom'
            ? AnalyticsTime::customRange(
                $this->stringParam('startDate'),
                $this->stringParam('endDate'),
            )
            : AnalyticsTime::presetRange($range);

        // An unknown preset or malformed custom date falls back to the default window instead
        // of erroring: these params come from our own CP UI, and a dashboard showing the last
        // 7 days beats one showing an error banner.
        $resolved ??= AnalyticsTime::presetRange(self::DEFAULT_RANGE)
            ?? throw new \LogicException('The default range preset must resolve.');

        // Both ends are floored to a {@see self::RANGE_BUCKET_SECONDS}-second boundary so that
        // windows running up to "now" share a stable cache key within each bucket instead of
        // producing a unique per-second key on every render. Presets whose endAt already lies in
        // the past pass through untouched, so historical ranges are never truncated. Flooring
        // startAt matters for the rolling "24h" window; day-aligned starts already sit on a
        // minute boundary, and since the bucket divides evenly into 24h the rolling span stays
        // exactly 24 hours instead of drifting by the current second.
        return [
            $this->bucketMs($resolved['startAt']),
            min($resolved['endAt'], $this->bucketMs(time() * 1000)),
            $resolved['unit'],
            $this->bucketMs($resolved['compareStartAt']),
        ];
    }

    /**
     * Range totals, plus the equal-length preceding window under a `comparison` key.
     *
     * The client derives its KPI trend percentages from this pair. Without a prior window
     * it has nothing to compare against, so `comparison` is omitted rather than defaulted —
     * a missing key means "no trend", which is not the same as a prior period of zero.
     *
     * @return array<string,mixed>|null
     */
    private function totalsWithComparison(int $startAt, int $endAt, int $compareStartAt): ?array
    {
        $analytics = Observatory::getInstance()->analytics;
        $totals = $analytics->getTotals($startAt, $endAt);

        if ($totals === null) {
            return null;
        }

        // The prior window covers the same elapsed span, one whole period earlier — the anchor
        // comes from AnalyticsTime, which steps back in calendar units so the two windows sit at
        // the same phase. Matching the elapsed span rather than the period's full length is what
        // makes a period-to-date preset comparable: at 15:00 on Wednesday, "this week" weighs
        // Mon–Wed against the previous Mon–Wed, not against a complete seven-day week.
        //
        // It deliberately is *not* the window immediately preceding this one. That is equal in
        // length but wrong in phase: it made "today" compare this morning against yesterday
        // evening, and "this week" compare Mon–Wed against Fri–Sun.
        // Clamped to stay strictly before $startAt: getTotals() bounds are inclusive at both
        // ends, and for the rolling "24h" preset the elapsed span equals the period exactly, so
        // the prior window would otherwise end on the very instant this one begins and count the
        // event there twice.
        $elapsed = max(0, $endAt - $startAt);
        $comparison = $analytics->getTotals($compareStartAt, min($compareStartAt + $elapsed, $startAt - 1));

        return $comparison !== null ? $totals + ['comparison' => $comparison] : $totals;
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

    /**
     * Reads a request param that is expected to be a string.
     *
     * Query strings can carry arrays (`?range[]=x`), and casting one with `(string)` raises an
     * "Array to string conversion" — which Craft escalates to an exception in dev mode, turning a
     * malformed param into a 500 rather than the documented fallback. Anything that is not a
     * string is simply not the param we were given.
     */
    private function stringParam(string $name, string $default = ''): string
    {
        $value = \Craft::$app->getRequest()->getParam($name, $default);

        return \is_string($value) ? $value : $default;
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

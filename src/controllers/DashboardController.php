<?php

namespace szenario\craftumamiis\controllers;

use craft\web\Controller;
use szenario\craftumamiis\UmamiIs;
use yii\web\Response;

class DashboardController extends Controller
{
    private const DEFAULT_METRIC_TYPES = ['url', 'referrer', 'browser', 'os', 'device', 'country', 'region', 'city'];
    private const ALLOWED_PAGEVIEW_UNITS = ['hour', 'day', 'month', 'year'];

    /**
     * Compact 7-day summary for the dashboard widget.
     */
    public function actionGetWidgetSummary(): Response
    {
        $plugin = UmamiIs::getInstance();
        $plugin->sync->autoSyncMissingDays();

        return $this->asJson($plugin->stats->getWidgetSummary());
    }

    /**
     * Get all dashboard widget data in one request.
     *
     * @return Response|null
     */
    public function actionGetDashboardData(): ?Response
    {
        $request = \Craft::$app->getRequest();
        $startAt = (int) $request->getParam('startAt', strtotime('-7 days') * 1000);
        $endAt = (int) $request->getParam('endAt', time() * 1000);
        $unit = $this->normalizePageviewUnit($request->getParam('unit'));
        if ($unit === null) {
            return $this->asFailure('Invalid pageview unit', ['error' => 'Invalid pageview unit']);
        }

        $includePageviews = (string) $request->getParam('includePageviews', '1') !== '0';

        $plugin = UmamiIs::getInstance();
        $metrics = $plugin->client->getMetricsBatch($startAt, $endAt, self::DEFAULT_METRIC_TYPES);

        return $this->asJson([
            'pageviews' => $includePageviews ? $plugin->client->getPageviews($startAt, $endAt, $unit) : null,
            'stats' => $plugin->client->getStats($startAt, $endAt),
            'metrics' => $metrics,
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
        $startAt = $request->getParam('startAt', strtotime('-7 days') * 1000);
        $endAt = $request->getParam('endAt', time() * 1000);
        $unit = $this->normalizePageviewUnit($request->getParam('unit'));
        if ($unit === null) {
            return $this->asFailure('Invalid pageview unit', ['error' => 'Invalid pageview unit']);
        }

        $pageviews = UmamiIs::getInstance()->client->getPageviews(
            (int) $startAt,
            (int) $endAt,
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
        $request = \Craft::$app->getRequest();
        $startAt = $request->getParam('startAt', strtotime('-7 days') * 1000);
        $endAt = $request->getParam('endAt', time() * 1000);

        $stats = UmamiIs::getInstance()->client->getStats(
            (int) $startAt,
            (int) $endAt
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
        $startAt = $request->getParam('startAt', strtotime('-7 days') * 1000);
        $endAt = $request->getParam('endAt', time() * 1000);
        $typeParam = $request->getParam('type');
        $typesParams = $request->getParam('types');

        if (!$typeParam && !$typesParams) {
            return $this->asFailure('Missing metric type(s)', ['error' => 'Missing metric type(s)']);
        }

        if ($typesParams) {
            $types = $this->normalizeMetricTypes(explode(',', (string) $typesParams));
            if (empty($types)) {
                return $this->asFailure('Invalid metric type(s)', ['error' => 'Invalid metric type(s)']);
            }

            return $this->asJson(UmamiIs::getInstance()->client->getMetricsBatch((int) $startAt, (int) $endAt, $types));
        }

        $types = $this->normalizeMetricTypes([(string) $typeParam]);
        if (empty($types)) {
            return $this->asFailure('Invalid metric type', ['error' => 'Invalid metric type']);
        }

        $metrics = UmamiIs::getInstance()->client->getMetrics(
            (int) $startAt,
            (int) $endAt,
            $types[0]
        );

        return $this->asJson($metrics ?? []);
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
        $types = array_filter($types, fn (string $type) => \in_array($type, self::DEFAULT_METRIC_TYPES, true));

        return array_values(array_unique($types));
    }
}

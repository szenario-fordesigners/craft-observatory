<?php

namespace szenario\craftumamiis\controllers;

use craft\web\Controller;
use szenario\craftumamiis\UmamiIs;
use yii\web\Response;

class DashboardController extends Controller
{
    /**
     * Get updated pageviews for the dashboard widget via AJAX.
     *
     * @return Response
     */
    public function actionGetPageviews(): Response
    {
        $request = \Craft::$app->getRequest();
        $startAt = $request->getParam('startAt', strtotime('-7 days') * 1000);
        $endAt = $request->getParam('endAt', time() * 1000);
        $unit = $request->getParam('unit', 'day');

        $pageviews = UmamiIs::getInstance()->analytics->getPageviews(
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

        $stats = UmamiIs::getInstance()->analytics->getStats(
            (int) $startAt,
            (int) $endAt
        );

        return $this->asJson($stats ?? []);
    }

    /**
     * Get specific metrics for the dashboard widget via AJAX.
     *
     * @return Response
     */
    public function actionGetMetrics(): Response
    {
        $request = \Craft::$app->getRequest();
        $startAt = $request->getParam('startAt', strtotime('-7 days') * 1000);
        $endAt = $request->getParam('endAt', time() * 1000);
        $typeParam = $request->getParam('type');
        $typesParams = $request->getParam('types');

        if (!$typeParam && !$typesParams) {
            return $this->asErrorJson('Missing metric type(s)');
        }

        if ($typesParams) {
            $types = explode(',', (string) $typesParams);
            $metrics = [];
            foreach ($types as $type) {
                $metrics[$type] = UmamiIs::getInstance()->analytics->getMetrics(
                    (int) $startAt,
                    (int) $endAt,
                    trim($type)
                ) ?? [];
            }
            return $this->asJson($metrics);
        }

        $metrics = UmamiIs::getInstance()->analytics->getMetrics(
            (int) $startAt,
            (int) $endAt,
            (string) $typeParam
        );

        return $this->asJson($metrics ?? []);
    }
}

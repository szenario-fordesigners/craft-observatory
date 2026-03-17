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
     * Get specific metrics for the dashboard widget via AJAX.
     *
     * @return Response
     */
    public function actionGetMetrics(): Response
    {
        $request = \Craft::$app->getRequest();
        $startAt = $request->getParam('startAt', strtotime('-7 days') * 1000);
        $endAt = $request->getParam('endAt', time() * 1000);
        $type = $request->getParam('type');

        if (!$type) {
            return $this->asErrorJson('Missing metric type');
        }

        $metrics = UmamiIs::getInstance()->analytics->getMetrics(
            (int) $startAt,
            (int) $endAt,
            $type
        );

        return $this->asJson($metrics ?? []);
    }
}

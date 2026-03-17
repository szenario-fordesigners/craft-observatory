<?php

namespace szenario\craftumamiis\console\controllers;

use craft\console\Controller;
use szenario\craftumamiis\UmamiIs;

class AnalyticsController extends Controller
{
    /**
     * Test the getActiveVisitors method of the Analytics service.
     */
    public function actionTestActive()
    {
        $this->stdout("Fetching active visitors...\n");

        $visitors = UmamiIs::getInstance()->analytics->getActiveVisitors();

        if ($visitors !== null) {
            $this->stdout("Active visitors: {$visitors}\n");
        } else {
            $this->stderr("Failed to fetch active visitors. Check logs for details.\n");
        }
    }
}

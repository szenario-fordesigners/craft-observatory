<?php

namespace szenario\craftobservatory\console\controllers;

use craft\console\Controller;
use szenario\craftobservatory\Observatory;

class AnalyticsController extends Controller
{
    /**
     * Tests the live visitors method of the selected analytics source.
     */
    public function actionTestActive()
    {
        $this->stdout("Fetching active visitors...\n");

        $visitors = Observatory::getInstance()->analytics->getLiveVisitors();

        if ($visitors !== null) {
            $this->stdout("Active visitors: {$visitors}\n");
        } else {
            $this->stderr("Failed to fetch active visitors. Check logs for details.\n");
        }
    }
}

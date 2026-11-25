<?php

namespace szenario\craftobservatory\console\controllers;

use craft\console\Controller;
use szenario\craftobservatory\services\SyncCoordinator;
use yii\console\ExitCode;

/**
 * Lists the Observatory commands when `craft observatory` is run on its own.
 */
class DefaultController extends Controller
{
    /**
     * Shows the available Observatory commands.
     *
     * @return int
     */
    public function actionIndex(): int
    {
        // ponytail: hand-written, update when a command is added to console/controllers.
        $max = SyncCoordinator::MAX_SYNC_DAYS;

        $this->stdout(<<<TXT
        Observatory commands

          craft observatory/sync [days] [--force]
              Pull closed days from the analytics source into the local mirror.
              Days already synced are skipped. Today is never fetched.

              days      How many closed days back to cover (default 30, max {$max}).
              --force   Refetch days that are already marked as synced.

              Examples:
                craft observatory/sync
                craft observatory/sync 90 --force

        TXT);

        return ExitCode::OK;
    }
}

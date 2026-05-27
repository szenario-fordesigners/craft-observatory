<?php

namespace szenario\craftumamiis;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\log\MonologTarget;
use craft\services\Dashboard;
use Psr\Log\LogLevel;
use szenario\craftumamiis\models\Settings;
use szenario\craftumamiis\services\StatsReport;
use szenario\craftumamiis\services\SyncCoordinator;
use szenario\craftumamiis\services\UmamiClient;
use szenario\craftumamiis\widgets\UmamiIsHeatmapWidget;
use szenario\craftumamiis\widgets\UmamiIsSummaryWidget;
use yii\base\Event;

/**
 * umami plugin
 *
 * @property-read UmamiClient $client
 * @property-read StatsReport $stats
 * @property-read SyncCoordinator $sync
 * @method static UmamiIs getInstance()
 * @method Settings getSettings()
 * @author szenario
 * @copyright szenario
 * @license https://craftcms.github.io/license/ Craft License
 */
class UmamiIs extends Plugin
{
    /**
     * Number of closed (pre-today) days the events widget reads from the local mirror.
     * The widget shows this many DB-backed days plus today's live counts (total = this + 1).
     */
    public const EVENTS_CLOSED_DAYS = 6;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'client' => UmamiClient::class,
                'stats' => StatsReport::class,
                'sync' => SyncCoordinator::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->registerLogTarget();
        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function () {
            // ...
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate('umami-is/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerLogTarget(): void
    {
        $log = Craft::$app->getLog();
        $targets = $log->targets;

        foreach ($targets as $target) {
            if ($target instanceof MonologTarget && $target->name === 'umami-is') {
                return;
            }
        }

        $targets[] = Craft::createObject([
            'class' => MonologTarget::class,
            'name' => 'umami-is',
            'extractExceptionTrace' => !App::devMode(),
            'allowLineBreaks' => App::devMode(),
            'level' => App::devMode() ? LogLevel::DEBUG : LogLevel::INFO,
            'categories' => ['umami-is'],
            'logContext' => App::devMode(),
        ]);

        $log->targets = $targets;
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = UmamiIsSummaryWidget::class;
            $event->types[] = UmamiIsHeatmapWidget::class;
            $event->types[] = \szenario\craftumamiis\widgets\UmamiIsWorldMapWidget::class;
            $event->types[] = \szenario\craftumamiis\widgets\UmamiIsReferrersWidget::class;
            $event->types[] = \szenario\craftumamiis\widgets\UmamiIsCountriesWidget::class;
            $event->types[] = \szenario\craftumamiis\widgets\UmamiIsDevicesWidget::class;
            $event->types[] = \szenario\craftumamiis\widgets\UmamiIsEventsWidget::class;
            $event->types[] = \szenario\craftumamiis\widgets\UmamiIsLiveVisitorsWidget::class;
        });
    }
}

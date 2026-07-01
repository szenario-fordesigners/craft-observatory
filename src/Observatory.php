<?php

namespace szenario\craftobservatory;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\App;
use craft\log\MonologTarget;
use craft\services\Dashboard;
use Psr\Log\LogLevel;
use szenario\craftobservatory\models\Settings;
use szenario\craftobservatory\services\Analytics;
use szenario\craftobservatory\services\StatsReport;
use szenario\craftobservatory\services\SyncCoordinator;
use szenario\craftobservatory\widgets\ObservatoryHeatmapWidget;
use szenario\craftobservatory\widgets\ObservatoryVisitorsWidget;
use yii\base\Event;

/**
 * Observatory plugin
 *
 * @property-read Analytics $analytics
 * @property-read StatsReport $stats
 * @property-read SyncCoordinator $sync
 * @method static Observatory getInstance()
 * @method Settings getSettings()
 * @author szenario
 * @copyright szenario
 * @license https://craftcms.github.io/license/ Craft License
 */
class Observatory extends Plugin
{
    /**
     * Number of closed (pre-today) days the events widget reads from the local mirror.
     * The widget shows this many DB-backed days plus today's live counts (total = this + 1).
     */
    public const EVENTS_CLOSED_DAYS = 6;

    /**
     * Breakdown dimensions persisted per closed day in the local mirror
     * (`observatory_daily_stats.metrics`).
     *
     * Drives two things that must stay in lockstep: which breakdowns SyncCoordinator
     * fetches for each closed day, and which ones StatsReport may serve from the mirror
     * instead of querying the provider live. A type absent here is always fetched live.
     *
     * Only count-summable dimensions belong here — summing daily pageview/session counts
     * per label across days is correct. Unique-visitor style metrics are NOT summable and
     * must never be mirror-served (see StatsReport::getRangeBreakdowns()).
     */
    public const MIRRORED_METRIC_TYPES = [
        'url', 'title', 'entry', 'exit', 'referrer', 'channel',
        'browser', 'os', 'device', 'country', 'region', 'city',
    ];

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = true;

    public static function config(): array
    {
        return [
            'components' => [
                'analytics' => Analytics::class,
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
        return Craft::$app->view->renderTemplate('observatory/_settings.twig', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    private function registerLogTarget(): void
    {
        $log = Craft::$app->getLog();
        $targets = $log->targets;

        foreach ($targets as $target) {
            if ($target instanceof MonologTarget && $target->name === 'observatory') {
                return;
            }
        }

        $targets[] = Craft::createObject([
            'class' => MonologTarget::class,
            'name' => 'observatory',
            'extractExceptionTrace' => !App::devMode(),
            'allowLineBreaks' => App::devMode(),
            'level' => App::devMode() ? LogLevel::DEBUG : LogLevel::INFO,
            'categories' => ['observatory'],
            'logContext' => App::devMode(),
        ]);

        $log->targets = $targets;
    }

    private function attachEventHandlers(): void
    {
        // Register event handlers here ...
        // (see https://craftcms.com/docs/5.x/extend/events.html to get started)
        Event::on(Dashboard::class, Dashboard::EVENT_REGISTER_WIDGET_TYPES, function (RegisterComponentTypesEvent $event) {
            $event->types[] = ObservatoryVisitorsWidget::class;
            $event->types[] = ObservatoryHeatmapWidget::class;
            $event->types[] = \szenario\craftobservatory\widgets\ObservatoryWorldMapWidget::class;
            $event->types[] = \szenario\craftobservatory\widgets\ObservatoryReferrersWidget::class;
            $event->types[] = \szenario\craftobservatory\widgets\ObservatoryCountriesWidget::class;
            $event->types[] = \szenario\craftobservatory\widgets\ObservatoryDevicesWidget::class;
            $event->types[] = \szenario\craftobservatory\widgets\ObservatoryEventsWidget::class;
            $event->types[] = \szenario\craftobservatory\widgets\ObservatoryLiveVisitorsWidget::class;
            $event->types[] = \szenario\craftobservatory\widgets\ObservatoryUsageWidget::class;
        });
    }
}

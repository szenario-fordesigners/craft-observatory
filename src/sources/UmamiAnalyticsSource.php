<?php

namespace szenario\craftumamiis\sources;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use szenario\craftumamiis\services\UmamiClient;
use szenario\craftumamiis\UmamiIs;

/**
 * Legacy analytics source backed by the original Umami API client.
 *
 * @author szenario
 * @since 1.0.0
 */
class UmamiAnalyticsSource extends Component implements AnalyticsSourceInterface
{
    // Private Properties
    // =========================================================================

    /**
     * Original Umami transport.
     */
    private ?UmamiClient $_client = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getStatus(): array
    {
        return UmamiIs::getInstance()->client->getStatus() + [
            'source' => 'umami',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getStorageKey(): ?string
    {
        $websiteId = App::parseEnv(UmamiIs::getInstance()->getSettings()->umamiWebsiteId);

        return empty($websiteId) ? null : "umami:{$websiteId}";
    }

    /**
     * @inheritdoc
     */
    public function getLiveVisitors(): ?int
    {
        return $this->_client()->getActiveVisitors();
    }

    /**
     * @inheritdoc
     */
    public function getTotals(int $startAt, int $endAt, int $cacheDuration = 60): ?array
    {
        $stats = $this->_client()->getStats($startAt, $endAt, $cacheDuration);

        if ($stats === null) {
            return null;
        }

        $stats['sessionDurationSeconds'] = (int) ($stats['totaltime'] ?? 0);
        unset($stats['totaltime']);

        return $stats;
    }

    /**
     * @inheritdoc
     */
    public function getPageviews(int $startAt, int $endAt, string $unit = 'day'): ?array
    {
        return $this->_client()->getPageviews($startAt, $endAt, $unit);
    }

    /**
     * @inheritdoc
     */
    public function getBreakdown(int $startAt, int $endAt, string $type, int $cacheDuration = 300): ?array
    {
        return $this->_client()->getMetrics($startAt, $endAt, $type);
    }

    /**
     * @inheritdoc
     */
    public function getBreakdowns(int $startAt, int $endAt, array $types, int $cacheDuration = 300, int $concurrency = 8): array
    {
        return $this->_client()->getMetricsBatch($startAt, $endAt, $types, $cacheDuration, $concurrency);
    }

    /**
     * @inheritdoc
     */
    public function getDailyStatsAndBreakdownsBatch(array $days, array $breakdownTypes, int $concurrency = 8): array
    {
        return $this->_client()->getDailyStatsAndMetricsBatch($days, $breakdownTypes, $concurrency);
    }

    /**
     * @inheritdoc
     */
    public function getHourlyPageviewsBatch(array $days, int $concurrency = 8): array
    {
        return $this->_client()->getHourlyPageviewsBatch($days, $concurrency);
    }

    /**
     * @inheritdoc
     */
    public function getEventsBatch(array $days, int $concurrency = 8): array
    {
        return $this->_client()->getEventsBatch($days, $concurrency);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the original Umami client component.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _client(): UmamiClient
    {
        if ($this->_client === null) {
            $this->_client = Craft::createObject(UmamiClient::class);
        }

        return $this->_client;
    }
}

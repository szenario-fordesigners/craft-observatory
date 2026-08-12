<?php

namespace szenario\craftobservatory\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use szenario\craftobservatory\models\Settings;
use szenario\craftobservatory\Observatory;
use szenario\craftobservatory\sources\AnalyticsSourceInterface;
use szenario\craftobservatory\sources\PostHogAnalyticsSource;

/**
 * Selects and exposes the configured analytics source.
 *
 * @author szenario
 * @since 1.0.0
 */
class Analytics extends Component implements AnalyticsSourceInterface
{
    // Private Properties
    // =========================================================================

    /**
     * Lazily-created selected source.
     */
    private ?AnalyticsSourceInterface $_source = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the configured source implementation.
     *
     * @author szenario
     * @since 1.0.0
     */
    public function source(): AnalyticsSourceInterface
    {
        if ($this->_source !== null) {
            return $this->_source;
        }

        $settings = Observatory::getInstance()->getSettings();
        $source = App::parseEnv($settings->analyticsSource) ?: Settings::SOURCE_POSTHOG;

        // Add future providers as new match arms.
        $class = match ($source) {
            default => PostHogAnalyticsSource::class,
        };

        $this->_source = Craft::createObject($class);

        return $this->_source;
    }

    /**
     * @inheritdoc
     */
    public function getStatus(): array
    {
        return $this->source()->getStatus();
    }

    /**
     * @inheritdoc
     */
    public function getStorageKey(): ?string
    {
        return $this->source()->getStorageKey();
    }

    /**
     * @inheritdoc
     */
    public function getLiveVisitors(): ?int
    {
        return $this->source()->getLiveVisitors();
    }

    /**
     * @inheritdoc
     */
    public function getTotals(int $startAt, int $endAt, int $cacheDuration = 60): ?array
    {
        return $this->source()->getTotals($startAt, $endAt, $cacheDuration);
    }

    /**
     * @inheritdoc
     */
    public function getPageviews(int $startAt, int $endAt, string $unit = 'day'): ?array
    {
        return $this->source()->getPageviews($startAt, $endAt, $unit);
    }

    /**
     * @inheritdoc
     */
    public function getBreakdown(int $startAt, int $endAt, string $type, int $cacheDuration = 300): ?array
    {
        return $this->source()->getBreakdown($startAt, $endAt, $type, $cacheDuration);
    }

    /**
     * @inheritdoc
     */
    public function getBreakdowns(int $startAt, int $endAt, array $types, int $cacheDuration = 300, int $concurrency = 2): array
    {
        return $this->source()->getBreakdowns($startAt, $endAt, $types, $cacheDuration, $concurrency);
    }

    /**
     * @inheritdoc
     */
    public function getDailyStatsAndBreakdownsBatch(array $days, array $breakdownTypes, int $concurrency = 2): array
    {
        return $this->source()->getDailyStatsAndBreakdownsBatch($days, $breakdownTypes, $concurrency);
    }

    /**
     * @inheritdoc
     */
    public function getHourlyPageviewsBatch(array $days, int $concurrency = 2): array
    {
        return $this->source()->getHourlyPageviewsBatch($days, $concurrency);
    }

    /**
     * @inheritdoc
     */
    public function getEventsBatch(array $days, int $concurrency = 2): array
    {
        return $this->source()->getEventsBatch($days, $concurrency);
    }
}

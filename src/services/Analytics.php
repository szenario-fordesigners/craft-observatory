<?php

namespace szenario\craftumamiis\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;
use szenario\craftumamiis\records\DailyStats;
use szenario\craftumamiis\UmamiIs;

/**
 * Analytics service
 */
class Analytics extends Component
{
    /**
     * Get the number of active users on the website.
     *
     * @return int|null The number of active visitors, or null on error.
     */
    public function getActiveVisitors(): ?int
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::error('Umami Website ID is required.', __METHOD__);
            return null;
        }

        $cacheKey = "umami_active_visitors_{$websiteId}";
        $cache = Craft::$app->getCache();

        $cachedVisitors = $cache->get($cacheKey);
        if ($cachedVisitors !== false) {
            return (int) $cachedVisitors;
        }

        $url = rtrim(App::parseEnv($settings->umamiUrl), '/');
        $apiKey = App::parseEnv($settings->umamiApiKey);

        if (empty($url) || empty($apiKey)) {
            Craft::error('Umami settings are incomplete. URL and API Key are required.', __METHOD__);
            return null;
        }

        $client = Craft::createGuzzleClient(['timeout' => 5.0, 'connect_timeout' => 3.0]);

        try {
            // Clean up the URL to prevent double slashes or accidental paths
            $cleanUrl = preg_replace('#/(api|v1)/?$#', '', $url);

            // Use Umami Cloud API format (apikey + websiteid)
            $endpointUrl = "{$cleanUrl}/v1/websites/{$websiteId}/active";

            $headers = [
                'Accept' => 'application/json',
                'x-umami-api-key' => $apiKey,
            ];

            $response = $client->request('GET', $endpointUrl, [
                'headers' => $headers,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['visitors'])) {
                $visitors = (int) $body['visitors'];
                $cache->set($cacheKey, $visitors, 60); // Cache for 60 seconds
                return $visitors;
            }

            Craft::error("Unexpected response from Umami API: " . print_r($body, true), __METHOD__);
        } catch (GuzzleException $e) {
            Craft::error("Error fetching active visitors from Umami: {$e->getMessage()}", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error("Unexpected error fetching active visitors from Umami: {$e->getMessage()}", __METHOD__);
        }

        return null;
    }

    /**
     * Saves daily stats retrieved from Umami into the local database.
     * 
     * @param string $date The date in 'Y-m-d' format
     * @param array $stats The raw stats array from the API
     * @return bool True if successful, false otherwise.
     */
    public function syncDailyStats(string $date, array $stats, array $metrics = []): bool
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::error('Cannot sync stats without an Umami Website ID.', __METHOD__);
            return false;
        }

        // See if a record already exists for this website and date
        $record = DailyStats::findOne([
            'websiteId' => $websiteId,
            'date' => $date,
        ]);

        if (!$record) {
            $record = new DailyStats();
            $record->websiteId = $websiteId;
            $record->date = $date;
        }

        // Extract values (handle potentially missing keys gracefully depending on API response format)
        $record->pageviews = (int) ($stats['pageviews'] ?? 0);
        $record->visitors = (int) ($stats['visitors'] ?? 0);
        $record->visits = (int) ($stats['visits'] ?? 0);
        $record->bounces = (int) ($stats['bounces'] ?? 0);
        $record->totaltime = (int) ($stats['totaltime'] ?? 0);
        
        if (!empty($metrics)) {
            $record->metrics = json_encode($metrics);
        }

        if (!$record->save()) {
            Craft::error("Failed to save daily stats for {$date}: " . json_encode($record->getErrors()), __METHOD__);
            return false;
        }

        return true;
    }

    /**
     * Get a daily stats report for the last X days.
     * Returns an array of stats per day, including whether it came from the DB or the API.
     *
     * @param int $days Number of days to include (including today)
     * @return array
     */
    public function getDailyStatsReport(int $days = 30): array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            return [];
        }

        $report = [];
        $todayStr = date('Y-m-d');

        // Pre-fetch all available DB records for the requested timeframe
        $startDateStr = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));

        $dbRecords = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $startDateStr])
            ->indexBy('date')
            ->all();

        for ($i = 0; $i < $days; $i++) {
            $dateStr = date('Y-m-d', strtotime("-{$i} days"));
            $isToday = ($dateStr === $todayStr);

            if (!$isToday && isset($dbRecords[$dateStr])) {
                $record = $dbRecords[$dateStr];
                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => $record->pageviews,
                    'visitors' => $record->visitors,
                    'visits' => $record->visits,
                    'bounces' => $record->bounces,
                    'totaltime' => $record->totaltime,
                    'metrics' => $record->metrics ? json_decode($record->metrics, true) : [],
                    'source' => 'DB',
                ];
            } else {
                // Fetch from API
                $startAt = strtotime($dateStr . ' midnight') * 1000;
                $endAt = $isToday ? (time() * 1000) : (strtotime($dateStr . ' 23:59:59') * 1000);

                $stats = $this->getStats($startAt, $endAt);

                $pageviews = (int) ($stats['pageviews'] ?? 0);
                $visitors = (int) ($stats['visitors'] ?? 0);
                $visits = (int) ($stats['visits'] ?? 0);
                $bounces = (int) ($stats['bounces'] ?? 0);
                $totaltime = (int) ($stats['totaltime'] ?? 0);

                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => $pageviews,
                    'visitors' => $visitors,
                    'visits' => $visits,
                    'bounces' => $bounces,
                    'totaltime' => $totaltime,
                    'metrics' => [],
                    'source' => 'API',
                ];

                // If it's a past day missing from the DB, sync it now
                if (!$isToday && $stats) {
                    $this->syncDailyStats($dateStr, $stats);
                }
            }
        }

        return $report;
    }

    /**     * Get statistics for the website.
     *
     * @param int $startAt Timestamp (in ms)
     * @param int $endAt Timestamp (in ms)
     * @return array|null The stats data, or null on error.
     */
    public function getStats(int $startAt, int $endAt): ?array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::error('Umami Website ID is required.', __METHOD__);
            return null;
        }

        $cacheKey = "umami_stats_{$websiteId}_{$startAt}_{$endAt}";
        $cache = Craft::$app->getCache();

        $cachedStats = $cache->get($cacheKey);
        if ($cachedStats !== false) {
            return $cachedStats;
        }

        $url = rtrim(App::parseEnv($settings->umamiUrl), '/');
        $apiKey = App::parseEnv($settings->umamiApiKey);

        if (empty($url) || empty($apiKey)) {
            Craft::error('Umami settings are incomplete. URL and API Key are required.', __METHOD__);
            return null;
        }

        $client = Craft::createGuzzleClient(['timeout' => 5.0, 'connect_timeout' => 3.0]);
        try {
            $cleanUrl = preg_replace('#/(api|v1)/?$#', '', $url);
            $endpointUrl = "{$cleanUrl}/v1/websites/{$websiteId}/stats";

            $headers = [
                'Accept' => 'application/json',
                'x-umami-api-key' => $apiKey,
            ];

            $response = $client->request('GET', $endpointUrl, [
                'headers' => $headers,
                'query' => [
                    'startAt' => $startAt,
                    'endAt' => $endAt,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (is_array($body)) {
                $cache->set($cacheKey, $body, 300); // Cache for 5 minutes
                return $body;
            }
        } catch (\Throwable $e) {
            Craft::error("Unexpected error fetching stats from Umami: {$e->getMessage()}", __METHOD__);
        }

        return null;
    }

    /**     * Get pageviews for a given timeframe.
     *
     * @param int $startAt Timestamp (in ms)
     * @param int $endAt Timestamp (in ms)
     * @param string $unit The time unit (e.g. 'hour', 'day')
     * @return array|null The pageviews data, or null on error.
     */
    public function getPageviews(int $startAt, int $endAt, string $unit = 'day'): ?array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::error('Umami Website ID is required.', __METHOD__);
            return null;
        }

        $cacheKey = "umami_pageviews_{$websiteId}_{$startAt}_{$endAt}_{$unit}";
        $cache = Craft::$app->getCache();

        $cachedPageviews = $cache->get($cacheKey);
        if ($cachedPageviews !== false) {
            return $cachedPageviews;
        }

        $url = rtrim(App::parseEnv($settings->umamiUrl), '/');
        $apiKey = App::parseEnv($settings->umamiApiKey);

        if (empty($url) || empty($apiKey)) {
            Craft::error('Umami settings are incomplete. URL and API Key are required.', __METHOD__);
            return null;
        }

        $client = Craft::createGuzzleClient(['timeout' => 5.0, 'connect_timeout' => 3.0]);

        try {
            $cleanUrl = preg_replace('#/(api|v1)/?$#', '', $url);
            $endpointUrl = "{$cleanUrl}/v1/websites/{$websiteId}/pageviews";

            $headers = [
                'Accept' => 'application/json',
                'x-umami-api-key' => $apiKey,
            ];

            $response = $client->request('GET', $endpointUrl, [
                'headers' => $headers,
                'query' => [
                    'startAt' => $startAt,
                    'endAt' => $endAt,
                    'unit' => $unit,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            if (isset($body['pageviews'])) {
                $cache->set($cacheKey, $body, 300); // Cache for 5 minutes
                return $body;
            }

            Craft::error("Unexpected response from Umami API: " . print_r($body, true), __METHOD__);
        } catch (GuzzleException $e) {
            Craft::error("Error fetching pageviews from Umami: {$e->getMessage()}", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error("Unexpected error fetching pageviews from Umami: {$e->getMessage()}", __METHOD__);
        }

        return null;
    }

    /**
     * Get metrics for a specific type (e.g. url, referrer, browser, os, device, country, region, city).
     *
     * @param int $startAt Timestamp (in ms)
     * @param int $endAt Timestamp (in ms)
     * @param string $type The metric type
     * @return array|null The metrics data, or null on error.
     */
    public function getMetrics(int $startAt, int $endAt, string $type): ?array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::error('Umami Website ID is required.', __METHOD__);
            return null;
        }

        $cacheKey = "umami_metrics_{$websiteId}_{$startAt}_{$endAt}_{$type}";
        $cache = Craft::$app->getCache();

        $cachedMetrics = $cache->get($cacheKey);
        if ($cachedMetrics !== false) {
            return $cachedMetrics;
        }

        $url = rtrim(App::parseEnv($settings->umamiUrl), '/');
        $apiKey = App::parseEnv($settings->umamiApiKey);

        if (empty($url) || empty($apiKey)) {
            Craft::error('Umami settings are incomplete. URL and API Key are required.', __METHOD__);
            return null;
        }

        $client = Craft::createGuzzleClient(['timeout' => 5.0, 'connect_timeout' => 3.0]);

        try {
            $cleanUrl = preg_replace('#/(api|v1)/?$#', '', $url);
            $endpointUrl = "{$cleanUrl}/v1/websites/{$websiteId}/metrics";

            $headers = [
                'Accept' => 'application/json',
                'x-umami-api-key' => $apiKey,
            ];

            $response = $client->request('GET', $endpointUrl, [
                'headers' => $headers,
                'query' => [
                    'startAt' => $startAt,
                    'endAt' => $endAt,
                    'type' => $type,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);

            // Umami metrics API returns a direct array, not wrapped in an object property,
            // or an empty array.
            if (is_array($body)) {
                $cache->set($cacheKey, $body, 300); // Cache for 5 minutes
                return $body;
            }

            Craft::error("Unexpected response from Umami API: " . print_r($body, true), __METHOD__);
        } catch (GuzzleException $e) {
            Craft::error("Error fetching metrics from Umami: {$e->getMessage()}", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error("Unexpected error fetching metrics from Umami: {$e->getMessage()}", __METHOD__);
        }

        return null;
    }
}

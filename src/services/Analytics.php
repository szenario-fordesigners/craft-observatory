<?php

namespace szenario\craftumamiis\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use szenario\craftumamiis\jobs\SyncMissingDaysJob;
use szenario\craftumamiis\records\DailyStats;
use szenario\craftumamiis\UmamiIs;

/**
 * Analytics service
 */
class Analytics extends Component
{
    /**
     * Returns a shared Guzzle client, base URL and headers for Umami API calls.
     *
     * @return array{0:\GuzzleHttp\Client,1:string,2:array<string,string>}|null
     */
    private function getUmamiHttpContext(): ?array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);
        $url = rtrim(App::parseEnv($settings->umamiUrl), '/');
        $apiKey = App::parseEnv($settings->umamiApiKey);

        if (empty($websiteId) || empty($url) || empty($apiKey)) {
            Craft::error('Umami settings are incomplete. Website ID, URL and API Key are required.', __METHOD__);
            return null;
        }

        $cleanUrl = preg_replace('#/(api|v1)/?$#', '', $url);
        $baseUrl = "{$cleanUrl}/v1/websites/{$websiteId}";

        $headers = [
            'Accept' => 'application/json',
            'x-umami-api-key' => $apiKey,
        ];

        $client = Craft::createGuzzleClient([
            'timeout' => 10.0,
            'connect_timeout' => 3.0,
        ]);

        return [$client, $baseUrl, $headers];
    }

    /**
     * Fetches stats and metrics for many days concurrently.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $days
     * @param string[] $metricsTypes
     * @param int $concurrency
     * @return array<string,array{stats:?array,metrics:array,errors:array<int,string>}>
     */
    public function getDailyStatsAndMetricsBatch(array $days, array $metricsTypes, int $concurrency = 8): array
    {
        $ctx = $this->getUmamiHttpContext();
        if ($ctx === null) {
            return [];
        }

        [$client, $baseUrl, $headers] = $ctx;

        $results = [];
        foreach ($days as $day) {
            $results[$day['date']] = [
                'stats' => null,
                'metrics' => [],
                'errors' => [],
            ];
        }

        $requests = function () use ($days, $metricsTypes, $baseUrl, $headers) {
            foreach ($days as $day) {
                $date = $day['date'];
                $startAt = $day['startAt'];
                $endAt = $day['endAt'];

                $statsUri = "{$baseUrl}/stats?startAt={$startAt}&endAt={$endAt}";
                yield "stats:{$date}" => new Request('GET', $statsUri, $headers);

                foreach ($metricsTypes as $type) {
                    $metricsUri = "{$baseUrl}/metrics?startAt={$startAt}&endAt={$endAt}&type=" . rawurlencode($type);
                    yield "metrics:{$date}:{$type}" => new Request('GET', $metricsUri, $headers);
                }
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => function ($response, $index) use (&$results) {
                $key = (string) $index;
                $body = json_decode($response->getBody()->getContents(), true);

                if (!is_array($body)) {
                    return;
                }

                if (str_starts_with($key, 'stats:')) {
                    $date = substr($key, strlen('stats:'));
                    if (isset($results[$date])) {
                        $results[$date]['stats'] = $body;
                    }
                    return;
                }

                if (str_starts_with($key, 'metrics:')) {
                    // metrics:<date>:<type>
                    $parts = explode(':', $key, 3);
                    $date = $parts[1] ?? null;
                    $type = $parts[2] ?? null;
                    if ($date && $type && isset($results[$date])) {
                        $results[$date]['metrics'][$type] = $body;
                    }
                }
            },
            'rejected' => function ($reason, $index) use (&$results) {
                $key = (string) $index;

                if (str_starts_with($key, 'stats:')) {
                    $date = substr($key, strlen('stats:'));
                    if (isset($results[$date])) {
                        $results[$date]['errors'][] = "Stats request failed: {$reason}";
                    }
                    return;
                }

                if (str_starts_with($key, 'metrics:')) {
                    $parts = explode(':', $key, 3);
                    $date = $parts[1] ?? null;
                    $type = $parts[2] ?? null;
                    if ($date && isset($results[$date])) {
                        $typeLabel = $type ? " ({$type})" : '';
                        $results[$date]['errors'][] = "Metrics request failed{$typeLabel}: {$reason}";
                    }
                }
            },
        ]);

        // Execute all requests. Pool uses curl_multi under the hood.
        $pool->promise()->wait();

        return $results;
    }

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
     * Queues a background job to sync any historical days missing from the local DB.
     * Render-path cost: one indexed query for MAX(dateUpdated) plus at most one queue insert.
     * Today is always skipped — it's still accumulating and handled by the live widget queries.
     *
     * @param int $days How many days back to check (excluding today).
     * @param int $throttleSeconds Minimum seconds between sync attempts. Derived from MAX(dateUpdated) on DailyStats.
     * @return bool True if a job was queued, false if throttled or already pending.
     */
    public function autoSyncMissingDays(int $days = 30, int $throttleSeconds = 900): bool
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);

        if (empty($websiteId)) {
            Craft::warning('autoSyncMissingDays skipped: no websiteId configured.', 'umami-is');
            return false;
        }

        $lastUpdatedStr = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->max('dateUpdated');

        if ($lastUpdatedStr !== null && time() - strtotime($lastUpdatedStr) < $throttleSeconds) {
            $age = time() - strtotime($lastUpdatedStr);
            Craft::debug("autoSyncMissingDays throttled: last sync {$age}s ago (window {$throttleSeconds}s).", 'umami-is');
            return false;
        }

        // Dedupe pending jobs across rapid concurrent renders. The job itself clears this key on completion.
        $cache = Craft::$app->getCache();
        $pendingKey = "umami_autosync_pending_{$websiteId}";
        if (!$cache->add($pendingKey, 1, $throttleSeconds)) {
            Craft::debug('autoSyncMissingDays skipped: a sync job is already pending.', 'umami-is');
            return false;
        }

        Craft::$app->getQueue()->push(new SyncMissingDaysJob([
            'websiteId' => $websiteId,
            'days' => $days,
        ]));

        Craft::info("Queued SyncMissingDaysJob for websiteId={$websiteId}, days={$days}.", 'umami-is');
        return true;
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

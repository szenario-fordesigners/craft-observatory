<?php

namespace szenario\craftumamiis\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use szenario\craftumamiis\UmamiIs;

/**
 * HTTP transport for the Umami website API.
 *
 * Handles auth, base-URL composition, response decoding, optional caching, and a
 * concurrent batch fetch via Guzzle's Pool. Higher-level reporting and sync logic
 * live in StatsReport and SyncCoordinator respectively.
 */
class UmamiClient extends Component
{
    /**
     * Returns a shared Guzzle client, base URL, headers and websiteId for Umami API calls.
     *
     * @return array{0:\GuzzleHttp\Client,1:string,2:array<string,string>,3:string}|null
     */
    private function getUmamiHttpContext(float $timeout = 5.0): ?array
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
            'timeout' => $timeout,
            'connect_timeout' => 3.0,
        ]);

        return [$client, $baseUrl, $headers, $websiteId];
    }

    /**
     * Performs a cached GET against the Umami website API.
     *
     * @param string $path Path appended to the website base URL, e.g. '/stats'.
     * @param array<string,scalar> $query Query string parameters.
     * @param string|null $cacheKey If provided, response is cached/served under this key.
     * @param int $cacheDuration TTL in seconds; ignored when $cacheKey is null or 0.
     * @param float $timeout Per-request HTTP timeout in seconds.
     * @return array<mixed>|null Decoded body, or null on failure / non-array response.
     */
    private function umamiGet(string $path, array $query = [], ?string $cacheKey = null, int $cacheDuration = 0, float $timeout = 5.0): ?array
    {
        $cache = Craft::$app->getCache();
        if ($cacheKey !== null) {
            $cached = $cache->get($cacheKey);
            if ($cached !== false) {
                return $cached;
            }
        }

        $ctx = $this->getUmamiHttpContext($timeout);
        if ($ctx === null) {
            return null;
        }
        [$client, $baseUrl, $headers] = $ctx;

        try {
            $response = $client->request('GET', "{$baseUrl}{$path}", [
                'headers' => $headers,
                'query' => $query,
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            if (!\is_array($body)) {
                Craft::error("Unexpected Umami response at {$path}: " . print_r($body, true), __METHOD__);
                return null;
            }

            if ($cacheKey !== null && $cacheDuration > 0) {
                $cache->set($cacheKey, $body, $cacheDuration);
            }
            return $body;
        } catch (GuzzleException $e) {
            Craft::error("Umami GET {$path} failed: {$e->getMessage()}", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error("Umami GET {$path} unexpected error: {$e->getMessage()}", __METHOD__);
        }

        return null;
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
        $ctx = $this->getUmamiHttpContext(10.0);
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

                if (!\is_array($body)) {
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

        // Pool uses curl_multi under the hood.
        $pool->promise()->wait();

        return $results;
    }

    /**
     * Get the number of active users on the website.
     */
    public function getActiveVisitors(): ?int
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);
        if (empty($websiteId)) {
            return null;
        }

        $body = $this->umamiGet('/active', [], "umami_active_visitors_{$websiteId}", 60);
        return isset($body['visitors']) ? (int) $body['visitors'] : null;
    }

    /**
     * @param int $startAt Timestamp (in ms)
     * @param int $endAt Timestamp (in ms)
     * @param int $cacheDuration Cache duration in seconds
     */
    public function getStats(int $startAt, int $endAt, int $cacheDuration = 60): ?array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);
        if (empty($websiteId)) {
            return null;
        }

        return $this->umamiGet(
            '/stats',
            ['startAt' => $startAt, 'endAt' => $endAt],
            "umami_stats_{$websiteId}_{$startAt}_{$endAt}",
            $cacheDuration,
        );
    }

    /**
     * @param int $startAt Timestamp (in ms)
     * @param int $endAt Timestamp (in ms)
     * @param string $unit Time unit (e.g. 'hour', 'day')
     */
    public function getPageviews(int $startAt, int $endAt, string $unit = 'day'): ?array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);
        if (empty($websiteId)) {
            return null;
        }

        $body = $this->umamiGet(
            '/pageviews',
            ['startAt' => $startAt, 'endAt' => $endAt, 'unit' => $unit],
            "umami_pageviews_{$websiteId}_{$startAt}_{$endAt}_{$unit}",
            60,
        );
        return isset($body['pageviews']) ? $body : null;
    }

    /**
     * @param int $startAt Timestamp (in ms)
     * @param int $endAt Timestamp (in ms)
     * @param string[] $types Metric types to fetch.
     * @return array<string,array<mixed>>
     */
    public function getMetricsBatch(int $startAt, int $endAt, array $types, int $cacheDuration = 300, int $concurrency = 8): array
    {
        $types = array_values(array_unique(array_filter(array_map('trim', $types))));
        $results = array_fill_keys($types, []);

        if (empty($types)) {
            return $results;
        }

        $ctx = $this->getUmamiHttpContext();
        if ($ctx === null) {
            return $results;
        }

        [$client, $baseUrl, $headers, $websiteId] = $ctx;
        $cache = Craft::$app->getCache();
        $missing = [];

        foreach ($types as $type) {
            $cacheKey = "umami_metrics_{$websiteId}_{$startAt}_{$endAt}_{$type}";
            $cached = $cache->get($cacheKey);
            if ($cached !== false && \is_array($cached)) {
                $results[$type] = $cached;
                continue;
            }

            $missing[$type] = $cacheKey;
        }

        if (empty($missing)) {
            return $results;
        }

        $requests = function () use ($missing, $baseUrl, $headers, $startAt, $endAt) {
            foreach ($missing as $type => $_cacheKey) {
                $metricsUri = "{$baseUrl}/metrics?startAt={$startAt}&endAt={$endAt}&type=" . rawurlencode((string) $type);
                yield (string) $type => new Request('GET', $metricsUri, $headers);
            }
        };

        $pool = new Pool($client, $requests(), [
            'concurrency' => max(1, $concurrency),
            'fulfilled' => function ($response, $index) use (&$results, $missing, $cache, $cacheDuration) {
                $type = (string) $index;
                $body = json_decode($response->getBody()->getContents(), true);

                if (!\is_array($body)) {
                    Craft::error("Unexpected Umami metrics response for {$type}: " . print_r($body, true), __METHOD__);
                    return;
                }

                $results[$type] = $body;

                if ($cacheDuration > 0 && isset($missing[$type])) {
                    $cache->set($missing[$type], $body, $cacheDuration);
                }
            },
            'rejected' => function ($reason, $index) {
                Craft::error("Umami metrics request ({$index}) failed: {$reason}", __METHOD__);
            },
        ]);

        try {
            $pool->promise()->wait();
        } catch (\Throwable $e) {
            Craft::error("Umami metrics batch request failed: {$e->getMessage()}", __METHOD__);
        }

        return $results;
    }

    /**
     * @param int $startAt Timestamp (in ms)
     * @param int $endAt Timestamp (in ms)
     * @param string $type Metric type (url, referrer, browser, os, device, country, region, city, ...)
     */
    public function getMetrics(int $startAt, int $endAt, string $type): ?array
    {
        $settings = UmamiIs::getInstance()->getSettings();
        $websiteId = App::parseEnv($settings->umamiWebsiteId);
        if (empty($websiteId)) {
            return null;
        }

        return $this->umamiGet(
            '/metrics',
            ['startAt' => $startAt, 'endAt' => $endAt, 'type' => $type],
            "umami_metrics_{$websiteId}_{$startAt}_{$endAt}_{$type}",
            300,
        );
    }
}

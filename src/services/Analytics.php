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
            if (!is_array($body)) {
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
     * Returns the configured Craft application timezone, used to compute day boundaries.
     */
    private function appTimeZone(): \DateTimeZone
    {
        return new \DateTimeZone(Craft::$app->getTimeZone());
    }

    /**
     * Returns Y-m-d for a given offset in days from today, in the app timezone.
     * DST-safe: uses DateTimeImmutable arithmetic instead of strtotime.
     */
    public function dateOffset(int $daysAgo): string
    {
        return (new \DateTimeImmutable('today', $this->appTimeZone()))
            ->modify("-{$daysAgo} days")
            ->format('Y-m-d');
    }

    /**
     * Returns [startMs, endMs] for a Y-m-d date in the app timezone.
     * Start is local midnight; end is one second before the next local midnight, so DST
     * spring-forward / fall-back days are bounded correctly.
     *
     * @return array{0:int,1:int}
     */
    public function dayBounds(string $dateStr): array
    {
        $start = new \DateTimeImmutable($dateStr . ' 00:00:00', $this->appTimeZone());
        $end = $start->modify('+1 day')->modify('-1 second');
        return [$start->getTimestamp() * 1000, $end->getTimestamp() * 1000];
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
            return null;
        }

        $body = $this->umamiGet('/active', [], "umami_active_visitors_{$websiteId}", 60);
        return isset($body['visitors']) ? (int) $body['visitors'] : null;
    }

    /**
     * Queues a background job to sync any historical days missing from the local DB.
     * Render-path cost: cache checks, one indexed query for MAX(dateUpdated), and at most one queue insert.
     * Today is always skipped — it's still accumulating and handled by the live widget queries.
     *
     * @param int $days How many days back to check (excluding today).
     * @param int $throttleSeconds Minimum seconds between sync attempts.
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

        $cache = Craft::$app->getCache();
        $lastAttemptKey = "umami_autosync_last_attempt_{$websiteId}";
        if ($cache->get($lastAttemptKey) !== false) {
            Craft::debug("autoSyncMissingDays throttled: a sync was attempted within the last {$throttleSeconds}s.", 'umami-is');
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
        $pendingKey = "umami_autosync_pending_{$websiteId}";
        if (!$cache->add($pendingKey, 1, $throttleSeconds)) {
            Craft::debug('autoSyncMissingDays skipped: a sync job is already pending.', 'umami-is');
            return false;
        }

        $cache->set($lastAttemptKey, 1, $throttleSeconds);

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
     * Historical days are read from the DB only; missing rows are filled by the queue.
     * Today is fetched live because it is still accumulating.
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
        $todayStr = $this->dateOffset(0);

        // Pre-fetch all available DB records for the requested timeframe
        $startDateStr = $this->dateOffset($days - 1);

        $dbRecords = DailyStats::find()
            ->where(['websiteId' => $websiteId])
            ->andWhere(['>=', 'date', $startDateStr])
            ->indexBy('date')
            ->all();

        // Round endAt down to a 60s bucket so the today-call shares a cache key across renders.
        $todayBucketSec = 60;
        $todayEndAt = (int) (floor(time() / $todayBucketSec) * $todayBucketSec * 1000);

        for ($i = 0; $i < $days; $i++) {
            $dateStr = $this->dateOffset($i);
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
            } elseif ($isToday) {
                // Today is still accumulating, so keep it live and out of the historical cache.
                [$startAt, ] = $this->dayBounds($dateStr);
                $stats = $this->getStats($startAt, $todayEndAt, $todayBucketSec);

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
            } else {
                $report[] = [
                    'date' => $dateStr,
                    'pageviews' => 0,
                    'visitors' => 0,
                    'visits' => 0,
                    'bounces' => 0,
                    'totaltime' => 0,
                    'metrics' => [],
                    'source' => 'Queued',
                ];
            }
        }

        return $report;
    }

    /**     * Get statistics for the website.
     *
     * @param int $startAt Timestamp (in ms)
     * @param int $endAt Timestamp (in ms)
     * @param int $cacheDuration Cache duration in seconds
     * @return array|null The stats data, or null on error.
     */
    public function getStats(int $startAt, int $endAt, int $cacheDuration = 300): ?array
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
            return null;
        }

        $body = $this->umamiGet(
            '/pageviews',
            ['startAt' => $startAt, 'endAt' => $endAt, 'unit' => $unit],
            "umami_pageviews_{$websiteId}_{$startAt}_{$endAt}_{$unit}",
            300,
        );
        return isset($body['pageviews']) ? $body : null;
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

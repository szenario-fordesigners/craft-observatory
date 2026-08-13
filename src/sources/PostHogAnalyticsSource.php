<?php

namespace szenario\craftobservatory\sources;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use szenario\craftobservatory\exceptions\AnalyticsRateLimitedException;
use szenario\craftobservatory\Observatory;

/**
 * Analytics source backed by PostHog's Query API.
 *
 * @author szenario
 * @since 1.0.0
 */
class PostHogAnalyticsSource extends Component implements AnalyticsSourceInterface
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getStatus(): array
    {
        $settings = Observatory::getInstance()->getSettings();
        $host = App::parseEnv($settings->posthogHost);
        $projectId = App::parseEnv($settings->posthogProjectId);
        $apiKey = App::parseEnv($settings->posthogPersonalApiKey);

        $configured = !empty($host) && !empty($projectId) && !empty($apiKey);

        return [
            'configured' => $configured,
            'apiKeyValid' => $configured ? !$this->_hasAuthError((string) $projectId) : false,
            'source' => 'posthog',
        ];
    }

    /**
     * @inheritdoc
     */
    public function getStorageKey(): ?string
    {
        $projectId = App::parseEnv(Observatory::getInstance()->getSettings()->posthogProjectId);

        return empty($projectId) ? null : "posthog:{$projectId}";
    }

    /**
     * @inheritdoc
     */
    public function getLiveVisitors(): ?int
    {
        $endAt = time() * 1000;
        $startAt = $endAt - 5 * 60 * 1000;

        $sql = sprintf(
            'SELECT count(DISTINCT distinct_id) AS visitors FROM events WHERE timestamp >= %s AND timestamp <= %s',
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
        );

        $rows = $this->_cachedQuery($sql, 'craft active visitors', 60);
        $row = $rows[0] ?? null;

        return \is_array($row) ? (int) $this->_cell($row, 0, 'visitors') : null;
    }

    /**
     * @inheritdoc
     */
    public function getTotals(int $startAt, int $endAt, int $cacheDuration = 60): ?array
    {
        $sql = sprintf(
            "SELECT count() AS pageviews, count(DISTINCT distinct_id) AS visitors, count(DISTINCT properties.\$session_id) AS visits FROM events WHERE event = '\$pageview' AND timestamp >= %s AND timestamp <= %s",
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
        );

        $rows = $this->_cachedQuery($sql, 'craft analytics totals', $cacheDuration);
        $row = $rows[0] ?? null;

        if (!\is_array($row)) {
            return null;
        }

        $sessionTotals = $this->_getSessionTotals($startAt, $endAt, $cacheDuration);

        return [
            'pageviews' => (int) $this->_cell($row, 0, 'pageviews'),
            'visitors' => (int) $this->_cell($row, 1, 'visitors'),
            'visits' => $sessionTotals['visits'] ?? (int) $this->_cell($row, 2, 'visits'),
            'sessionDurationSeconds' => $sessionTotals['sessionDurationSeconds'] ?? 0,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getPageviews(int $startAt, int $endAt, string $unit = 'day'): ?array
    {
        $bucket = $this->_bucketExpression($unit);
        if ($bucket === null) {
            return null;
        }

        $sql = sprintf(
            "SELECT bucket AS t, count() AS pageviews, count(DISTINCT session_id) AS sessions FROM (SELECT %s AS bucket, properties.\$session_id AS session_id FROM events WHERE event = '\$pageview' AND timestamp >= %s AND timestamp <= %s) GROUP BY bucket ORDER BY bucket ASC",
            $bucket,
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
        );

        $rows = $this->_cachedQuery($sql, 'craft analytics pageviews', 60);
        if ($rows === null) {
            return null;
        }

        $pageviews = [];
        $sessions = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $timestamp = (string) $this->_cell($row, 0, 't');
            $pageviews[] = [
                'x' => $timestamp,
                't' => $timestamp,
                'y' => (int) $this->_cell($row, 1, 'pageviews'),
            ];
            $sessions[] = [
                'x' => $timestamp,
                't' => $timestamp,
                'y' => (int) $this->_cell($row, 2, 'sessions'),
            ];
        }

        return [
            'pageviews' => $pageviews,
            'sessions' => $sessions,
        ];
    }

    /**
     * @inheritdoc
     */
    public function getBreakdown(int $startAt, int $endAt, string $type, int $cacheDuration = 300): ?array
    {
        $sql = $this->_breakdownSql($type, $startAt, $endAt);
        if ($sql === null) {
            return [];
        }

        $rows = $this->_cachedQuery($sql, "craft analytics {$type} breakdown", $cacheDuration);
        if ($rows === null) {
            return null;
        }

        return $this->_normalizeMetricRows($rows);
    }

    /**
     * @inheritdoc
     *
     * Fires every cache-missing breakdown concurrently through a Guzzle Pool so the
     * dashboard's many dimensions resolve in roughly one round-trip instead of a
     * serial waterfall. Cache hits are served inline and never reach the pool, and
     * each fresh result is cached under the same key {@see self::_cachedQuery()} uses,
     * so single and batch callers share one cache.
     *
     * Error signal: a type whose query **errored** is **omitted** from the returned
     * map; a type present with an empty array is a successful query that found no rows.
     * Callers that just render read `$result[$type] ?? []` and degrade gracefully; the
     * sync layer uses the present/absent distinction to tell a complete day from a
     * half-failed one (so it can retry the failure instead of freezing it).
     */
    public function getBreakdowns(int $startAt, int $endAt, array $types, int $cacheDuration = 300, int $concurrency = 2): array
    {
        $types = array_values(array_unique(array_filter(array_map('trim', $types))));
        $results = [];
        $queries = [];

        foreach ($types as $type) {
            $sql = $this->_breakdownSql($type, $startAt, $endAt);
            if ($sql === null) {
                // Unknown type is a deterministic empty, not an error — present as [].
                $results[$type] = [];
                continue;
            }

            $queries[$type] = $sql;
        }

        foreach ($this->_cachedQueries($queries, 'breakdown', $cacheDuration, $concurrency) as $type => $rows) {
            $results[$type] = $this->_normalizeMetricRows($rows);
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getDailyStatsAndBreakdownsBatch(array $days, array $breakdownTypes, int $concurrency = 2): array
    {
        $range = $this->_batchRange($days);
        if ($range === null) {
            return [];
        }

        [$startAt, $endAt, $dates] = $range;
        $eventDay = $this->_dayExpression('timestamp');
        $sessionDay = $this->_dayExpression('$start_timestamp');
        $eventRows = $this->_cachedQuery(sprintf(
            "SELECT %s AS day, count() AS pageviews, count(DISTINCT distinct_id) AS visitors, count(DISTINCT properties.\$session_id) AS visits FROM events WHERE event = '\$pageview' AND timestamp >= %s AND timestamp <= %s GROUP BY day ORDER BY day ASC",
            $eventDay,
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
        ), 'craft analytics daily totals range', 86400, true);
        $sessionRows = $this->_cachedQuery(sprintf(
            "SELECT %s AS day, count() AS visits, sum(\$session_duration) AS sessionDurationSeconds FROM sessions WHERE \$start_timestamp >= %s AND \$start_timestamp <= %s GROUP BY day ORDER BY day ASC",
            $sessionDay,
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
        ), 'craft analytics daily session totals range', 86400, true);

        $statsByDate = $eventRows === null ? null : array_fill_keys($dates, [
            'pageviews' => 0,
            'visitors' => 0,
            'visits' => 0,
            'sessionDurationSeconds' => 0,
        ]);
        if ($statsByDate !== null) {
            foreach ($eventRows as $row) {
                $date = $this->_dateKey($this->_cell($row, 0, 'day'));
                if ($date === null || !isset($statsByDate[$date])) {
                    continue;
                }
                $statsByDate[$date]['pageviews'] = (int) $this->_cell($row, 1, 'pageviews');
                $statsByDate[$date]['visitors'] = (int) $this->_cell($row, 2, 'visitors');
                $statsByDate[$date]['visits'] = (int) $this->_cell($row, 3, 'visits');
            }
            foreach ($sessionRows ?? [] as $row) {
                $date = $this->_dateKey($this->_cell($row, 0, 'day'));
                if ($date === null || !isset($statsByDate[$date])) {
                    continue;
                }
                $statsByDate[$date]['visits'] = (int) $this->_cell($row, 1, 'visits');
                $statsByDate[$date]['sessionDurationSeconds'] = (int) round((float) $this->_cell($row, 2, 'sessionDurationSeconds'));
            }
        }

        $queries = [];
        $knownTypes = [];
        foreach (array_values(array_unique($breakdownTypes)) as $type) {
            $sql = $this->_dailyBreakdownSql($type, $startAt, $endAt);
            if ($sql !== null) {
                $queries[$type] = $sql;
                $knownTypes[] = $type;
            }
        }
        $breakdownRows = $this->_cachedQueries($queries, 'daily breakdown range', 86400, $concurrency, true);
        $successfulTypes = array_keys($breakdownRows);
        $metricsByDate = array_fill_keys($dates, []);
        foreach ($successfulTypes as $type) {
            foreach ($dates as $date) {
                $metricsByDate[$date][$type] = [];
            }
            foreach ($breakdownRows[$type] as $row) {
                $date = $this->_dateKey($this->_cell($row, 0, 'day'));
                $x = $this->_cell($row, 1, 'x');
                $y = $this->_cell($row, 2, 'y');
                if ($date === null || !isset($metricsByDate[$date]) || $x === null || $x === '' || !is_numeric($y)) {
                    continue;
                }
                $metricsByDate[$date][$type][] = ['x' => (string) $x, 'y' => (int) $y];
            }
        }

        $failedBreakdowns = array_values(array_diff($knownTypes, $successfulTypes));
        $results = [];
        foreach ($dates as $date) {
            $errors = [];
            if ($statsByDate === null) {
                $errors[] = 'PostHog totals query failed';
            }
            if (!empty($failedBreakdowns)) {
                $errors[] = 'PostHog breakdown query failed: ' . implode(', ', $failedBreakdowns);
            }
            $results[$date] = [
                'stats' => $statsByDate[$date] ?? null,
                'metrics' => $metricsByDate[$date],
                'errors' => $errors,
            ];
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getHourlyPageviewsBatch(array $days, int $concurrency = 2): array
    {
        $range = $this->_batchRange($days);
        if ($range === null) {
            return [];
        }

        [$startAt, $endAt, $dates] = $range;
        $day = $this->_dayExpression('timestamp');
        $hour = $this->_hourExpression('timestamp');
        $rows = $this->_cachedQuery(sprintf(
            "SELECT %s AS day, %s AS hour, count() AS pageviews, count(DISTINCT properties.\$session_id) AS sessions FROM events WHERE event = '\$pageview' AND timestamp >= %s AND timestamp <= %s GROUP BY day, hour ORDER BY day ASC, hour ASC",
            $day,
            $hour,
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
        ), 'craft analytics hourly pageviews range', 86400, true);
        if ($rows === null) {
            return [];
        }

        $results = array_fill_keys($dates, []);
        foreach ($rows as $row) {
            $date = $this->_dateKey($this->_cell($row, 0, 'day'));
            if ($date === null || !isset($results[$date])) {
                continue;
            }
            $results[$date][] = [
                'hour' => (int) $this->_cell($row, 1, 'hour'),
                'visitors' => (int) $this->_cell($row, 3, 'sessions'),
                'pageviews' => (int) $this->_cell($row, 2, 'pageviews'),
            ];
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getEventsBatch(array $days, int $concurrency = 2): array
    {
        $range = $this->_batchRange($days);
        if ($range === null) {
            return [];
        }

        [$startAt, $endAt, $dates] = $range;
        $sql = $this->_dailyBreakdownSql('event', $startAt, $endAt);
        $rows = $sql === null ? null : $this->_cachedQuery($sql, 'craft analytics daily events range', 86400, true);
        if ($rows === null) {
            return [];
        }

        $results = array_fill_keys($dates, []);
        foreach ($rows as $row) {
            $date = $this->_dateKey($this->_cell($row, 0, 'day'));
            $x = $this->_cell($row, 1, 'x');
            $y = $this->_cell($row, 2, 'y');
            if ($date === null || !isset($results[$date]) || $x === null || $x === '' || !is_numeric($y)) {
                continue;
            }
            $results[$date][] = ['x' => (string) $x, 'y' => (int) $y];
        }

        return $results;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the cache key for a HogQL query's result rows.
     *
     * Keyed purely on the SQL so any caller running the same query — single or
     * batched — shares one cached result.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _queryCacheKey(string $sql): string
    {
        return 'posthog_query_' . md5($sql);
    }

    /**
     * Runs a cached HogQL query and returns its result rows.
     *
     * @return array<int,array<mixed>>|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _cachedQuery(string $sql, string $name, int $cacheDuration, bool $throwOnRateLimit = false): ?array
    {
        $cacheKey = $this->_queryCacheKey($sql);
        $cache = Craft::$app->getCache();
        $cached = $cache->get($cacheKey);

        if ($cached !== false && \is_array($cached)) {
            return $cached;
        }

        $rows = $this->_query($sql, $name, $throwOnRateLimit);

        if ($rows !== null && $cacheDuration > 0) {
            $cache->set($cacheKey, $rows, $cacheDuration);
        }

        return $rows;
    }

    /**
     * Runs several cached HogQL queries with at most two requests in flight.
     *
     * Failed queries are omitted so callers can distinguish them from successful
     * queries that returned no rows.
     *
     * @param array<string,string> $queries
     * @return array<string,array<int,array<mixed>>>
     */
    private function _cachedQueries(array $queries, string $name, int $cacheDuration, int $concurrency, bool $throwOnRateLimit = false): array
    {
        $cache = Craft::$app->getCache();
        $results = [];
        $pending = [];

        foreach ($queries as $key => $sql) {
            $cached = $cache->get($this->_queryCacheKey($sql));
            if ($cached !== false && \is_array($cached)) {
                $results[$key] = $cached;
            } else {
                $pending[$key] = $sql;
            }
        }

        if (empty($pending)) {
            return $results;
        }

        $context = $this->_httpContext();
        if ($context === null) {
            return $results;
        }

        [$host, $projectId, $apiKey] = $context;
        try {
            $this->_assertNotRateLimited($projectId);
        } catch (AnalyticsRateLimitedException $e) {
            if ($throwOnRateLimit) {
                throw $e;
            }
            return $results;
        }
        $url = "{$host}/api/projects/{$projectId}/query/";
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type' => 'application/json',
        ];
        $client = Craft::createGuzzleClient([
            'timeout' => 15.0,
            'connect_timeout' => 3.0,
        ]);
        $requests = function() use ($pending, $url, $headers, $name) {
            foreach ($pending as $key => $sql) {
                yield (string) $key => new Request('POST', $url, $headers, json_encode([
                    'query' => ['kind' => 'HogQLQuery', 'query' => $sql],
                    'name' => "craft analytics {$key} {$name}",
                ]));
            }
        };

        $rateLimitException = null;
        $pool = new Pool($client, $requests(), [
            'concurrency' => min(2, max(1, $concurrency)),
            'fulfilled' => function($response, $index) use (&$results, $pending, $cache, $cacheDuration, $projectId, $name) {
                $this->_clearAuthError($projectId);
                $key = (string) $index;
                $body = json_decode($response->getBody()->getContents(), true);
                $rows = \is_array($body) ? ($body['results'] ?? $body['query_status']['results'] ?? null) : null;
                if (!\is_array($rows)) {
                    Craft::error("PostHog response did not contain result rows for {$key} {$name}.", __METHOD__);
                    return;
                }
                if ($cacheDuration > 0) {
                    $cache->set($this->_queryCacheKey($pending[$key]), $rows, $cacheDuration);
                }
                $results[$key] = $rows;
            },
            'rejected' => function($reason, $index) use (&$rateLimitException, $projectId, $name) {
                if ($reason instanceof \Throwable) {
                    $rateLimitException = $this->_rateLimitException($reason, $projectId) ?? $rateLimitException;
                }
                if ($reason instanceof \Throwable && $this->_isAuthError($reason)) {
                    $this->_markAuthError($projectId);
                }
                Craft::error("PostHog {$name} request ({$index}) failed: {$reason}", __METHOD__);
            },
        ]);

        try {
            $pool->promise()->wait();
        } catch (\Throwable $e) {
            Craft::error("PostHog {$name} batch request failed: {$e->getMessage()}", __METHOD__);
        }

        if ($throwOnRateLimit && $rateLimitException !== null) {
            throw $rateLimitException;
        }

        return $results;
    }

    /**
     * Runs a HogQL query through the PostHog Query API.
     *
     * @return array<int,array<mixed>>|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _query(string $sql, string $name, bool $throwOnRateLimit = false): ?array
    {
        $context = $this->_httpContext();
        if ($context === null) {
            return null;
        }

        [$host, $projectId, $apiKey] = $context;
        try {
            $this->_assertNotRateLimited($projectId);
        } catch (AnalyticsRateLimitedException $e) {
            if ($throwOnRateLimit) {
                throw $e;
            }
            return null;
        }
        $url = "{$host}/api/projects/{$projectId}/query/";

        try {
            $client = Craft::createGuzzleClient([
                'timeout' => 15.0,
                'connect_timeout' => 3.0,
            ]);

            $response = $client->request('POST', $url, [
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'query' => [
                        'kind' => 'HogQLQuery',
                        'query' => $sql,
                    ],
                    'name' => $name,
                ],
            ]);

            $body = json_decode($response->getBody()->getContents(), true);
            if (!\is_array($body)) {
                Craft::error("Unexpected PostHog response for {$name}: " . print_r($body, true), __METHOD__);
                return null;
            }

            $this->_clearAuthError($projectId);

            $rows = $body['results'] ?? $body['query_status']['results'] ?? null;
            if (!\is_array($rows)) {
                Craft::error("PostHog response did not contain result rows for {$name}.", __METHOD__);
                return null;
            }

            return $rows;
        } catch (GuzzleException $e) {
            $rateLimitException = $this->_rateLimitException($e, $projectId);
            if ($throwOnRateLimit && $rateLimitException !== null) {
                throw $rateLimitException;
            }
            if ($this->_isAuthError($e)) {
                $this->_markAuthError($projectId);
            }
            Craft::error("PostHog query failed for {$name}: {$e->getMessage()}", __METHOD__);
        } catch (\Throwable $e) {
            Craft::error("PostHog query unexpected error for {$name}: {$e->getMessage()}", __METHOD__);
        }

        return null;
    }

    /**
     * Returns session count and summed session duration for a timestamp range.
     *
     * PostHog exposes `$session_duration` in seconds on the `sessions` table. Store
     * the sum, not the average, so reports can aggregate multiple days correctly.
     *
     * @return array{visits:int,sessionDurationSeconds:int}|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _getSessionTotals(int $startAt, int $endAt, int $cacheDuration): ?array
    {
        $sql = sprintf(
            "SELECT count() AS visits, sum(\$session_duration) AS sessionDurationSeconds FROM sessions WHERE \$start_timestamp >= %s AND \$start_timestamp <= %s",
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
        );

        $rows = $this->_cachedQuery($sql, 'craft analytics session totals', $cacheDuration);
        $row = $rows[0] ?? null;

        if (!\is_array($row)) {
            return null;
        }

        return [
            'visits' => (int) $this->_cell($row, 0, 'visits'),
            'sessionDurationSeconds' => (int) round((float) $this->_cell($row, 1, 'sessionDurationSeconds')),
        ];
    }

    /**
     * Returns normalized PostHog connection settings.
     *
     * @return array{0:string,1:string,2:string}|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _httpContext(): ?array
    {
        $settings = Observatory::getInstance()->getSettings();
        $host = rtrim(App::parseEnv($settings->posthogHost), '/');
        $projectId = App::parseEnv($settings->posthogProjectId);
        $apiKey = App::parseEnv($settings->posthogPersonalApiKey);

        if (empty($host) || empty($projectId) || empty($apiKey)) {
            Craft::error('PostHog settings are incomplete. Host, Project ID and Personal API Key are required.', __METHOD__);
            return null;
        }

        return [$host, (string) $projectId, (string) $apiKey];
    }

    /**
     * Returns the cache key used for sticky PostHog auth errors.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _authErrorCacheKey(string $projectId): string
    {
        return "posthog_auth_error_{$projectId}";
    }

    /**
     * Returns whether the selected project currently has a sticky auth error.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _hasAuthError(string $projectId): bool
    {
        return Craft::$app->getCache()->get($this->_authErrorCacheKey($projectId)) === true;
    }

    /**
     * Marks the selected project as having an auth error.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _markAuthError(string $projectId): void
    {
        Craft::$app->getCache()->set($this->_authErrorCacheKey($projectId), true, 3600);
    }

    /**
     * Clears a sticky auth error for the selected project.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _clearAuthError(string $projectId): void
    {
        Craft::$app->getCache()->delete($this->_authErrorCacheKey($projectId));
    }

    /**
     * Stops requests while a project-level PostHog cooldown is active.
     */
    private function _assertNotRateLimited(string $projectId): void
    {
        $retryAt = Craft::$app->getCache()->get($this->_rateLimitCacheKey($projectId));
        if ($retryAt !== false && (int) $retryAt > time()) {
            throw new AnalyticsRateLimitedException((int) $retryAt - time());
        }
    }

    /**
     * Converts a PostHog 429 into a shared project cooldown.
     */
    private function _rateLimitException(\Throwable $e, string $projectId): ?AnalyticsRateLimitedException
    {
        if (!$e instanceof RequestException || !$e->hasResponse() || $e->getResponse()->getStatusCode() !== 429) {
            return null;
        }

        $response = $e->getResponse();
        $retryAfter = trim($response->getHeaderLine('Retry-After'));
        $delay = ctype_digit($retryAfter) ? (int) $retryAfter : 0;

        if ($delay < 1 && preg_match('/Expected available in (\d+) seconds/i', (string) $response->getBody(), $matches)) {
            $delay = (int) $matches[1];
        }
        $delay = max(1, $delay ?: 60);

        Craft::$app->getCache()->set($this->_rateLimitCacheKey($projectId), time() + $delay, $delay);

        return new AnalyticsRateLimitedException($delay);
    }

    /**
     * Returns the shared project cooldown cache key.
     */
    private function _rateLimitCacheKey(string $projectId): string
    {
        return "posthog_rate_limit_{$projectId}";
    }

    /**
     * Returns whether an exception represents rejected PostHog credentials.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _isAuthError(\Throwable $e): bool
    {
        if (!$e instanceof RequestException || !$e->hasResponse()) {
            return false;
        }

        $statusCode = $e->getResponse()?->getStatusCode();

        return $statusCode === 401 || $statusCode === 403;
    }

    /**
     * Converts a millisecond timestamp into a HogQL DateTime expression.
     *
     * Emitted as an epoch, never a datetime string. HogQL parses bare datetime literals in
     * the *PostHog project's* timezone — `toDateTime()` is flagged tz_aware, so the project
     * zone is appended as its last argument automatically — while the day/hour grouping
     * expressions below bucket in Craft's timezone. A UTC-formatted string therefore shifted
     * every window by the project's UTC offset relative to its own buckets, silently dropping
     * the tail of each synced day. `fromUnixTimestamp()` is not tz_aware and takes an absolute
     * instant, so the bounds no longer depend on how the PostHog project is configured.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _toDateTime(int $ms): string
    {
        return 'fromUnixTimestamp(' . intdiv($ms, 1000) . ')';
    }

    /**
     * Returns the HogQL bucket expression for a time-series unit.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _bucketExpression(string $unit): ?string
    {
        return match ($unit) {
            'hour' => 'toStartOfHour(timestamp)',
            'day' => 'toStartOfDay(timestamp)',
            'month' => 'toStartOfMonth(timestamp)',
            'year' => 'toStartOfYear(timestamp)',
            default => null,
        };
    }

    /**
     * Returns the outer bounds and requested date keys for a sync batch.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $days
     * @return array{0:int,1:int,2:array<int,string>}|null
     */
    private function _batchRange(array $days): ?array
    {
        if (empty($days)) {
            return null;
        }

        return [
            min(array_column($days, 'startAt')),
            max(array_column($days, 'endAt')),
            array_values(array_unique(array_column($days, 'date'))),
        ];
    }

    /**
     * Normalizes a PostHog date/time value to the Craft site date key.
     */
    private function _dateKey(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable((string) $value, new \DateTimeZone(Craft::$app->getTimeZone())))
                ->setTimezone(new \DateTimeZone(Craft::$app->getTimeZone()))
                ->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns a HogQL calendar-day expression in Craft's timezone.
     */
    private function _dayExpression(string $column): string
    {
        return "toDate(toTimeZone({$column}, '{$this->_hogqlTimeZone()}'))";
    }

    /**
     * Returns a HogQL hour-of-day expression in Craft's timezone.
     */
    private function _hourExpression(string $column): string
    {
        return "toHour(toTimeZone({$column}, '{$this->_hogqlTimeZone()}'))";
    }

    /**
     * Escapes Craft's IANA timezone for a HogQL string literal.
     */
    private function _hogqlTimeZone(): string
    {
        return str_replace("'", "\\'", Craft::$app->getTimeZone());
    }

    /**
     * Returns the HogQL session-property expression for a session-scoped breakdown
     * type, or null when the type is event-scoped.
     *
     * Entry page, exit page and channel are properties of a *session*, not of any
     * single pageview, so they live on PostHog's `sessions` table and are queried over
     * the `$start_timestamp` window rather than the events path.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _sessionBreakdownExpression(string $type): ?string
    {
        return match ($type) {
            'entry' => "coalesce(nullIf(\$entry_pathname, ''), \$entry_current_url)",
            'exit' => "coalesce(nullIf(\$end_pathname, ''), \$end_current_url)",
            'channel' => '$channel_type',
            default => null,
        };
    }

    /**
     * Builds the HogQL query for a breakdown type, or null when the type is unknown.
     *
     * Single ({@see self::getBreakdown()}) and batch ({@see self::getBreakdowns()})
     * callers share this so both produce byte-identical SQL — and therefore the same
     * cache key. Custom events count from the `events` table, entry/exit/channel from
     * the session-scoped `sessions` table, and everything else counts `$pageview`s.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _breakdownSql(string $type, int $startAt, int $endAt): ?string
    {
        $from = $this->_toDateTime($startAt);
        $to = $this->_toDateTime($endAt);

        if ($type === 'event') {
            return sprintf(
                "SELECT event AS x, count() AS y FROM events WHERE timestamp >= %s AND timestamp <= %s AND NOT startsWith(event, '\$') GROUP BY event ORDER BY y DESC LIMIT 100",
                $from,
                $to,
            );
        }

        $sessionExpression = $this->_sessionBreakdownExpression($type);
        if ($sessionExpression !== null) {
            return sprintf(
                "SELECT %s AS x, count() AS y FROM sessions WHERE \$start_timestamp >= %s AND \$start_timestamp <= %s AND %s IS NOT NULL AND %s != '' GROUP BY x ORDER BY y DESC LIMIT 100",
                $sessionExpression,
                $from,
                $to,
                $sessionExpression,
                $sessionExpression,
            );
        }

        $expression = $this->_breakdownExpression($type);
        if ($expression === null) {
            return null;
        }

        return sprintf(
            "SELECT %s AS x, count() AS y FROM events WHERE event = '\$pageview' AND timestamp >= %s AND timestamp <= %s AND %s IS NOT NULL AND %s != '' GROUP BY x ORDER BY y DESC LIMIT 100",
            $expression,
            $from,
            $to,
            $expression,
            $expression,
        );
    }

    /**
     * Builds one range query returning the top 100 values per closed day.
     */
    private function _dailyBreakdownSql(string $type, int $startAt, int $endAt): ?string
    {
        $from = $this->_toDateTime($startAt);
        $to = $this->_toDateTime($endAt);

        if ($type === 'event') {
            $day = $this->_dayExpression('timestamp');
            $inner = sprintf(
                "SELECT %s AS day, event AS x, count() AS y FROM events WHERE timestamp >= %s AND timestamp <= %s AND NOT startsWith(event, '\$') GROUP BY day, x",
                $day,
                $from,
                $to,
            );
        } else {
            $sessionExpression = $this->_sessionBreakdownExpression($type);
            if ($sessionExpression !== null) {
                $day = $this->_dayExpression('$start_timestamp');
                $inner = sprintf(
                    "SELECT %s AS day, %s AS x, count() AS y FROM sessions WHERE \$start_timestamp >= %s AND \$start_timestamp <= %s AND %s IS NOT NULL AND %s != '' GROUP BY day, x",
                    $day,
                    $sessionExpression,
                    $from,
                    $to,
                    $sessionExpression,
                    $sessionExpression,
                );
            } else {
                $expression = $this->_breakdownExpression($type);
                if ($expression === null) {
                    return null;
                }
                $day = $this->_dayExpression('timestamp');
                $inner = sprintf(
                    "SELECT %s AS day, %s AS x, count() AS y FROM events WHERE event = '\$pageview' AND timestamp >= %s AND timestamp <= %s AND %s IS NOT NULL AND %s != '' GROUP BY day, x",
                    $day,
                    $expression,
                    $from,
                    $to,
                    $expression,
                    $expression,
                );
            }
        }

        return "SELECT day, x, y FROM (SELECT day, x, y, row_number() OVER (PARTITION BY day ORDER BY y DESC) AS row_num FROM ({$inner})) WHERE row_num <= 100 ORDER BY day ASC, y DESC LIMIT 50000";
    }

    /**
     * Returns the HogQL expression for a dashboard breakdown type.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _breakdownExpression(string $type): ?string
    {
        return match ($type) {
            'url' => "coalesce(nullIf(properties.\$pathname, ''), properties.\$current_url)",
            'title' => 'properties.$title',
            'referrer' => "coalesce(nullIf(properties.\$referring_domain, ''), properties.\$referrer)",
            'browser' => 'properties.$browser',
            'os' => 'properties.$os',
            'device' => 'properties.$device_type',
            'country' => 'properties.$geoip_country_code',
            'region' => 'properties.$geoip_subdivision_1_code',
            'city' => 'properties.$geoip_city_name',
            default => null,
        };
    }

    /**
     * Returns one cell from either associative or positional PostHog rows.
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _cell(array $row, int $index, string $key): mixed
    {
        return $row[$key] ?? $row[$index] ?? null;
    }

    /**
     * Normalizes PostHog result rows into the widget's `{x, y}` metric shape.
     *
     * @param array<int,array<mixed>> $rows
     * @return array<int,array{x:string,y:int}>
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _normalizeMetricRows(array $rows): array
    {
        $metrics = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $x = $this->_cell($row, 0, 'x');
            $y = $this->_cell($row, 1, 'y');

            if ($x === null || $x === '' || !is_numeric($y)) {
                continue;
            }

            $metrics[] = [
                'x' => (string) $x,
                'y' => (int) $y,
            ];
        }

        return $metrics;
    }
}

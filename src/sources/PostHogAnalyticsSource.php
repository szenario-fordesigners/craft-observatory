<?php

namespace szenario\craftobservatory\sources;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
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
        if ($type === 'event') {
            return $this->_getEvents($startAt, $endAt);
        }

        $sessionExpression = $this->_sessionBreakdownExpression($type);
        if ($sessionExpression !== null) {
            return $this->_getSessionBreakdown($startAt, $endAt, $type, $sessionExpression, $cacheDuration);
        }

        $expression = $this->_breakdownExpression($type);
        if ($expression === null) {
            return [];
        }

        $sql = sprintf(
            "SELECT %s AS x, count() AS y FROM events WHERE event = '\$pageview' AND timestamp >= %s AND timestamp <= %s AND %s IS NOT NULL AND %s != '' GROUP BY x ORDER BY y DESC LIMIT 100",
            $expression,
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
            $expression,
            $expression,
        );

        $rows = $this->_cachedQuery($sql, "craft analytics {$type} breakdown", $cacheDuration);
        if ($rows === null) {
            return null;
        }

        return $this->_normalizeMetricRows($rows);
    }

    /**
     * @inheritdoc
     */
    public function getBreakdowns(int $startAt, int $endAt, array $types, int $cacheDuration = 300, int $concurrency = 8): array
    {
        $types = array_values(array_unique(array_filter(array_map('trim', $types))));
        $results = [];

        foreach ($types as $type) {
            $results[$type] = $this->getBreakdown($startAt, $endAt, $type, $cacheDuration) ?? [];
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getDailyStatsAndBreakdownsBatch(array $days, array $breakdownTypes, int $concurrency = 8): array
    {
        $results = [];

        foreach ($days as $day) {
            $date = $day['date'];
            $stats = $this->getTotals($day['startAt'], $day['endAt'], 86400);
            $metrics = $this->getBreakdowns($day['startAt'], $day['endAt'], $breakdownTypes, 86400, $concurrency);

            $results[$date] = [
                'stats' => $stats,
                'metrics' => $metrics,
                'errors' => $stats === null ? ['PostHog totals query failed'] : [],
            ];
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getHourlyPageviewsBatch(array $days, int $concurrency = 8): array
    {
        $results = [];

        foreach ($days as $day) {
            $body = $this->getPageviews($day['startAt'], $day['endAt'], 'hour');
            $results[$day['date']] = $body ? $this->_parseHourlyPageviews($body) : [];
        }

        return $results;
    }

    /**
     * @inheritdoc
     */
    public function getEventsBatch(array $days, int $concurrency = 8): array
    {
        $results = [];

        foreach ($days as $day) {
            $results[$day['date']] = $this->_getEvents($day['startAt'], $day['endAt']) ?? [];
        }

        return $results;
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs a cached HogQL query and returns its result rows.
     *
     * @return array<int,array<mixed>>|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _cachedQuery(string $sql, string $name, int $cacheDuration): ?array
    {
        $cacheKey = 'posthog_query_' . md5($sql);
        $cache = Craft::$app->getCache();
        $cached = $cache->get($cacheKey);

        if ($cached !== false && \is_array($cached)) {
            return $cached;
        }

        $rows = $this->_query($sql, $name);

        if ($rows !== null && $cacheDuration > 0) {
            $cache->set($cacheKey, $rows, $cacheDuration);
        }

        return $rows;
    }

    /**
     * Runs a HogQL query through the PostHog Query API.
     *
     * @return array<int,array<mixed>>|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _query(string $sql, string $name): ?array
    {
        $context = $this->_httpContext();
        if ($context === null) {
            return null;
        }

        [$host, $projectId, $apiKey] = $context;
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
     * @author szenario
     * @since 1.0.0
     */
    private function _toDateTime(int $ms): string
    {
        $date = (new \DateTimeImmutable('@' . intdiv($ms, 1000)))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        return "toDateTime('{$date}')";
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
     * Returns the HogQL session-property expression for a session-scoped breakdown
     * type, or null when the type is event-scoped.
     *
     * Entry page, exit page and channel are properties of a *session*, not of any
     * single pageview, so they live on PostHog's `sessions` table and are queried by
     * {@see self::_getSessionBreakdown()} rather than the events path.
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
     * Runs a session-scoped breakdown against PostHog's `sessions` table.
     *
     * Mirrors {@see self::getBreakdown()}'s event path but counts sessions over the
     * `$start_timestamp` window instead of pageviews, so a row's count answers
     * "how many sessions" for that entry page / exit page / channel.
     *
     * @return array<int,array{x:string,y:int}>|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _getSessionBreakdown(int $startAt, int $endAt, string $type, string $expression, int $cacheDuration): ?array
    {
        $sql = sprintf(
            "SELECT %s AS x, count() AS y FROM sessions WHERE \$start_timestamp >= %s AND \$start_timestamp <= %s AND %s IS NOT NULL AND %s != '' GROUP BY x ORDER BY y DESC LIMIT 100",
            $expression,
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
            $expression,
            $expression,
        );

        $rows = $this->_cachedQuery($sql, "craft analytics {$type} breakdown", $cacheDuration);
        if ($rows === null) {
            return null;
        }

        return $this->_normalizeMetricRows($rows);
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

    /**
     * Parses a provider-neutral pageview response into hourly mirror rows.
     *
     * @param array{pageviews?:array<int,array{x?:string,t?:string,y?:int}>,sessions?:array<int,array{x?:string,t?:string,y?:int}>} $body
     * @return array<int,array{hour:int,visitors:int,pageviews:int}>
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _parseHourlyPageviews(array $body): array
    {
        $pageviewsByHour = [];
        foreach ($body['pageviews'] ?? [] as $entry) {
            $timestamp = $entry['t'] ?? $entry['x'] ?? null;
            if ($timestamp === null) {
                continue;
            }
            $hour = (int) date('G', strtotime((string) $timestamp));
            $pageviewsByHour[$hour] = (int) ($entry['y'] ?? 0);
        }

        $sessionsByHour = [];
        foreach ($body['sessions'] ?? [] as $entry) {
            $timestamp = $entry['t'] ?? $entry['x'] ?? null;
            if ($timestamp === null) {
                continue;
            }
            $hour = (int) date('G', strtotime((string) $timestamp));
            $sessionsByHour[$hour] = (int) ($entry['y'] ?? 0);
        }

        $hours = array_unique(array_merge(array_keys($pageviewsByHour), array_keys($sessionsByHour)));
        $rows = [];

        foreach ($hours as $hour) {
            $rows[] = [
                'hour' => (int) $hour,
                'visitors' => $sessionsByHour[$hour] ?? 0,
                'pageviews' => $pageviewsByHour[$hour] ?? 0,
            ];
        }

        return $rows;
    }

    /**
     * Returns top custom events for a range.
     *
     * @return array<int,array{x:string,y:int}>|null
     *
     * @author szenario
     * @since 1.0.0
     */
    private function _getEvents(int $startAt, int $endAt): ?array
    {
        $sql = sprintf(
            "SELECT event AS x, count() AS y FROM events WHERE timestamp >= %s AND timestamp <= %s AND NOT startsWith(event, '\$') GROUP BY event ORDER BY y DESC LIMIT 100",
            $this->_toDateTime($startAt),
            $this->_toDateTime($endAt),
        );

        $rows = $this->_cachedQuery($sql, 'craft analytics events', 300);

        return $rows === null ? null : $this->_normalizeMetricRows($rows);
    }
}

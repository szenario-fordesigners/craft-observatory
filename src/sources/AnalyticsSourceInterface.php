<?php

namespace szenario\craftobservatory\sources;

/**
 * Contract for analytics providers that can power the plugin's reports.
 *
 * The methods describe the report shapes the Craft widgets need, not any
 * provider's native API endpoints. Provider implementations are responsible for
 * translating these calls into their own query language or HTTP API.
 *
 * @author szenario
 * @since 1.0.0
 */
interface AnalyticsSourceInterface
{
    /**
     * Returns connectivity status for the selected analytics source.
     *
     * @return array{configured:bool,apiKeyValid:bool,source:string}
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getStatus(): array;

    /**
     * Returns the local mirror key for this source, or null when unconfigured.
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getStorageKey(): ?string;

    /**
     * Returns an approximate number of visitors active right now, when supported.
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getLiveVisitors(): ?int;

    /**
     * Returns aggregate totals for a timestamp range.
     *
     * @return array{pageviews:int,visitors:int,visits:int,sessionDurationSeconds:int}|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getTotals(int $startAt, int $endAt, int $cacheDuration = 60): ?array;

    /**
     * Returns pageview and session time series for a timestamp range.
     *
     * @return array{pageviews:array<int,array{x:string,t:string,y:int}>,sessions:array<int,array{x:string,t:string,y:int}>}|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getPageviews(int $startAt, int $endAt, string $unit = 'day'): ?array;

    /**
     * Returns a breakdown for a dimension such as country, referrer, browser, or URL.
     *
     * @return array<int,array{x:string,y:int}>|null
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getBreakdown(int $startAt, int $endAt, string $type, int $cacheDuration = 300): ?array;

    /**
     * Returns multiple breakdowns keyed by requested type.
     *
     * Error signal: a type whose query errored is omitted from the returned map; a type
     * present with an empty array is a successful query that found no rows. Callers that
     * just render read `$result[$type] ?? []` and degrade gracefully; {@see \szenario\craftobservatory\services\SyncCoordinator}
     * uses the present/absent distinction to tell a complete result from a failed one, so
     * it can retry the failure instead of freezing it as done. An implementation that
     * returns `[]` for both "no data" and "error" would silently defeat that retry logic.
     *
     * @param string[] $types
     * @return array<string,array<int,array{x:string,y:int}>>
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getBreakdowns(int $startAt, int $endAt, array $types, int $cacheDuration = 300, int $concurrency = 2): array;

    /**
     * Fetches daily totals and breakdowns for many closed days.
     *
     * Every requested date is always present as a key, unlike the batch methods below —
     * per-day failure is signalled through `stats: null` and/or a non-empty `errors` list
     * on that date's entry instead of the key being absent.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $days
     * @param string[] $breakdownTypes
     * @return array<string,array{stats:?array,metrics:array,errors:array<int,string>}>
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getDailyStatsAndBreakdownsBatch(array $days, array $breakdownTypes, int $concurrency = 2): array;

    /**
     * Fetches hourly visitor/pageview counts for many closed days.
     *
     * Error signal: a date whose fetch errored is omitted from the returned map, so
     * {@see \szenario\craftobservatory\services\SyncCoordinator} can tell "fetch failed,
     * retry later" apart from "fetch succeeded, day genuinely has zero traffic" (present
     * with an empty array). An implementation that returns `[]` for both would make a
     * provider outage look like a permanently synced zero-traffic day.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $days
     * @return array<string,array<int,array{hour:int,visitors:int,pageviews:int}>>
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getHourlyPageviewsBatch(array $days, int $concurrency = 2): array;

    /**
     * Fetches top custom events for many closed days.
     *
     * Same present/absent-key contract as {@see self::getHourlyPageviewsBatch()}: an
     * omitted date means the fetch failed and should be retried, not that the day had
     * no events.
     *
     * @param array<int,array{date:string,startAt:int,endAt:int}> $days
     * @return array<string,array<int,array{x:string,y:int}>>
     *
     * @author szenario
     * @since 1.0.0
     */
    public function getEventsBatch(array $days, int $concurrency = 2): array;
}

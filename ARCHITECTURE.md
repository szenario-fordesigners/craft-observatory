# Observatory — Data Flow, Caching & the Local Mirror

How analytics data reaches the dashboard, where it's cached, and why some of it
comes from the provider live while some comes from a local database mirror.

_Last updated: 2026-06-25._

---

## 1. Two consumers, two request shapes

The plugin renders analytics in two places, and they fetch differently:

- **Craft dashboard widgets** (`Countries`, `Referrers`, `Devices`, `Events`,
  `Heatmap`, `LiveVisitors`, `Usage`, `Visitors`, `WorldMap`). Each widget is an
  independent Vue app that issues **its own request**. The browser fires them in
  parallel (~6 concurrent connections), so several widgets load roughly in the
  time of the slowest one.
- **The Observatory CP page** (`frontend/src/CpApp.vue`). One Vue app that issues
  **one batched mirror-backed request** (`get-dashboard-data?includeStats=0`) for
  the chart + breakdown dimensions, and a separate live `get-stats` request for
  KPI totals. That keeps slow unique-visitor totals from blocking the mirror-backed
  sections.

Neither is "wrong" — batch when data is consumed together (the CP page), fan out
when the pieces are independent (scattered widgets). The thing that was wrong was
that the batched call ran its provider queries **serially** (see §5).

## 2. Endpoint → data source map

All endpoints live in `src/controllers/DashboardController.php`.

| Endpoint | Used by | Source |
|---|---|---|
| `get-dashboard-data` | CP page | **Mirror** breakdowns + **mirror** pageviews (day unit); optional **live** totals |
| `get-metrics` | Countries / Referrers / Devices / WorldMap | **Mirror** breakdown(s) + today live |
| `get-pageviews` | (chart, currently via get-dashboard-data) | **Mirror** (day + month units) + today live; **live** for hour |
| `get-heatmap-data` | Heatmap | **Mirror** (`HourlyStats`), 90 days |
| `get-top-events` | Events | **Mirror** (`DailyEvents`) + today live |
| `get-widget-summary` | Visitors | **Live** week totals + DB daily series |
| `get-usage-summary` | Usage | **Live** week totals + DB daily series |
| `get-active-visitors` | LiveVisitors | **Live** |
| `get-pageviews` / `get-stats` | CP chart/KPIs | **Live** |

"Live" means the configured `AnalyticsSourceInterface` implementation
(`PostHogAnalyticsSource` or `UmamiAnalyticsSource`), behind the response cache (§4).

## 3. The local mirror (durable cache)

Closed (past) days are immutable, so they're fetched from the provider **once** and
stored in DB tables, written by `SyncCoordinator` and read by `StatsReport`:

- `observatory_daily_stats` — per-day totals + a `metrics` JSON map of per-dimension
  top-100 breakdowns (`mediumText`; a dozen dimensions of long URLs overflow `TEXT`'s
  64 KB limit, which MySQL truncates silently).
- `observatory_hourly_stats` — per-(date, hour) visitor/pageview counts (heatmap).
- `observatory_daily_events` — per-day custom-event counts.
- `observatory_sync_state` — per-day, per-facet completeness markers (see §8).

Which breakdown dimensions get mirrored is defined once in
`Observatory::MIRRORED_METRIC_TYPES` and shared by both the writer (`SyncCoordinator`)
and the reader (`StatsReport`) so they can't drift.

**Today is never in the mirror** — it's still accumulating, so it's always fetched
live and merged on top of the closed days.

## 4. Caching layers (all server-side)

1. **Provider response cache** — `Craft::$app->getCache()`, keyed by query.
   - PostHog: `posthog_query_<md5(sql)>` (`PostHogAnalyticsSource::_queryCacheKey()`).
     Single (`getBreakdown`) and batch (`getBreakdowns`) paths build identical SQL, so
     they share one entry.
   - Umami: `observatory_metrics_<websiteId>_<startAt>_<endAt>_<type>`.
   - TTLs: live visitors 60s, totals 60s, breakdowns 300s, sync batch 86400s.
2. **Sticky auth-error flag** — caches the 401/403 *failure* state for 1h so
   `getStatus()` doesn't re-probe on every render; cleared on the next success.
3. **Sync throttle guards** — `SyncCoordinator` cache keys debounce auto-sync so it
   doesn't re-queue jobs on every page load.
4. **The mirror itself** (§3) — a permanent cache of immutable closed days.
5. **Range-key stabilization** — `DashboardController::bucketMs()` floors "now"-relative
   ranges to a 60s boundary so live cache keys stay stable instead of churning per-ms.

The **frontend caches nothing** (`useWidgetData.ts`, `CpApp.vue` re-fetch on mount and
range change). The only client mechanism is `AbortController` for race cancellation.

## 5. PostHog breakdown concurrency

`PostHogAnalyticsSource::getBreakdowns()` fires every cache-missing dimension
concurrently via a Guzzle `Pool` (mirroring `UmamiClient::getMetricsBatch()`). Cache
hits are served inline and never touch the network. Previously this was a serial
`foreach`, which made the CP page's ~11 dimensions a query waterfall.

## 6. Mirror-aware breakdowns (`StatsReport::getRangeBreakdowns()`)

This is the key optimization. For a date range it:

1. Sums each dimension's per-day top-100 from `observatory_daily_stats.metrics` for the
   closed days in range (one DB read).
2. Fetches **today** live (one concurrent `getBreakdowns` call) and merges it in.
3. Re-ranks each dimension to a top-100 `{x, y}` list.

So a 7- or 90-day breakdown grid costs **one live query for today + one DB read**,
regardless of range length — and stays correct on a cold cache, not just within the
TTL window. `get-dashboard-data` and `get-metrics` both route through it.

The pageview **time series** (`StatsReport::getRangePageviews()`) gets the same
treatment for `unit=day` and `unit=month`: closed days come from
`observatory_daily_stats` (per-day pageviews + sessions), bucketed by day or summed
per calendar month, with today folded in live (into its own day, or the current
month). Only `unit=hour` stays live — those ranges are a day or two and per-hour rows
aren't kept in this table. Buckets are emitted as local-midnight datetime strings
(day → that day, month → the 1st) so the mirror buckets and the live today point share
the browser timezone the chart parses with.

### Correctness constraints (why it's not "mirror everything")

The mirror is a **day-grained, top-100-per-day** store. Three hard limits follow, and
they're encoded in the design rather than left to chance:

- **Uniques don't sum.** Daily unique-visitor counts can't be added to get a range
  unique count (returning visitors double-count). So `MIRRORED_METRIC_TYPES` contains
  only count-summable dimensions (pageview/session counts), and **totals stay live** —
  `get-dashboard-data` still calls `getTotals()`/`getPageviews()` against the provider.
- **Top-N tail is approximate.** Summing daily top-100s is accurate for the head and
  loses the deep tail. Fine for top-5/top-list widgets.
- **Day granularity.** Range timestamps are floored to local dates, so sub-day windows
  still count whole days. The CP UI only ever emits day-aligned ranges
  (`useDateRange.ts` builds every range from `startOfDay()`), so this matches intent.
  Any type **not** in `MIRRORED_METRIC_TYPES`, and any caller needing sub-day precision,
  falls through to a fully live breakdown.

## 7. Known limitations / possible future work

- **No cache pre-warming.** Provider-cache entries are populated lazily on first miss;
  a user returning after the TTL pays a (now-small) cold load. A scheduled warm-up job
  could remove it.
- **Timezone seam.** The browser computes day boundaries in its TZ; the mirror is keyed
  by the Craft app TZ. If they differ, the today/closed split can be off by the offset
  at the edges. Consistent with the pre-existing live path, but worth unifying.
- **Sync coverage gates the mirror.** A dimension only serves from the mirror once
  `SyncCoordinator` has fetched it for the closed days; until then those days read empty
  and counts ramp up as the background sync completes.

## 8. Hardening roadmap — treat the store as an observable cache

The local store is a **materialized analytics cache**, not an exact mirror, and the
sync contract is its weak spot. Historically a single `DailyStats` row was the only
"this day is done" marker, so a day whose daily stats succeeded but whose breakdowns /
hourly / events partially failed got frozen as permanently incomplete — turning a
transient provider hiccup into silently undercounted local data.

The root cause sits one level above sync: **`getBreakdowns()` collapsed "query errored"
and "legitimately zero rows" into the same empty array**, so the sync layer literally
couldn't tell a complete day from a half-failed one. Two failure classes must stay
distinguishable, because they want opposite handling:

- **Errored** (network/timeout/auth, or a HogQL error like a missing session column) —
  should be retried.
- **Valid-but-empty** (a real day with no traffic, or a dimension a provider genuinely
  has no data for) — is a terminal success and must *not* be retried, or sync never
  converges (e.g. `$entry_pathname` erroring on an older PostHog would otherwise re-fetch
  all of history forever).

### Step 1 — preserve the error signal _(done)_

Across all three batch paths, a unit whose query **errored** is now **omitted** instead
of returned as `[]`: present-but-empty = a successful query with no rows; **absent =
errored**. Serving callers read `$result[$key] ?? []` and degrade gracefully; the sync
layer uses present/absent to tell a complete day from a half-failed one.

- `getBreakdowns()` omits errored dimensions; `getDailyStatsAndBreakdownsBatch()` reports
  the absent ones in each day's `errors`.
- `getHourlyPageviewsBatch()` and `getEventsBatch()` omit a day whose fetch errored.

### Step 2 — facet-level completeness + bounded retry _(done)_

The single "DailyStats row exists" marker is replaced by per-day, per-**facet** state in
`observatory_sync_state` — `{daily, breakdowns, hourly, events}`, four facets, not twelve
per-dimension flags (breakdowns fetch as one batch, so per-dimension state would be schema
bloat for a failure that's rarely per-dimension-independent).

- `SyncState` rows carry `status` (`done`/`failed`) + an `attempts` count. Absence = never
  attempted. A facet needs work when it's absent or failed under `SYNC_MAX_ATTEMPTS`.
- `findUnsyncedDaySpecs($facets)` returns days where any requested facet still needs work;
  each `fetchAndStore*` self-filters to its facet(s) and marks the outcome. **daily** and
  **breakdowns** are tracked apart, so totals (and the mirror-backed chart/KPIs) still land
  when breakdowns error, and the breakdowns facet retries and backfills the `metrics`.
- Retry is **bounded** by the attempt cap (successive passes are already spaced by the
  `autoSyncMissingDays()` throttle, so no separate time backoff is needed). The error-vs-empty
  signal from Step 1 keeps valid-empty results (zero-traffic days, dimensions a provider has
  no data for) terminal, so only genuine errors retry — and even those give up after the cap,
  so a structurally-broken facet can't re-fetch forever.
- Facets are scoped per caller: the recent-window job covers all four; backfill and the
  heatmap check omit `events` (only the recent window fetches events), or old days would
  look perpetually unsynced.

### Step 3 — CP freshness envelope _(done for the CP page)_

The CP `get-dashboard-data` response returns `{_syncing, lastSyncedAt, missingDays}`
for the closed-day facets it actually uses: `breakdowns`, plus `daily` when the chart
is served from the mirror (`unit=day|month`). The CP page shows a "still syncing /
data incomplete" state and polls quietly while retryable work remains. The heatmap
already exposes `_syncing` and now polls on the CP page too.

Still open: extend the same envelope to standalone breakdown widget endpoints, which
currently render partial mirror data without `lastSyncedAt` / `missingDays`.

### Step 4 — per-phase timing instrumentation _(planned)_

Measure local DB read, today-live fetch, totals-live fetch, and provider cache hit/miss
separately. Expectation after the breakdown work: the remaining slow paths are the
**live unique-visitor totals** (unavoidable — uniques don't sum) and **`unit=hour`**
charts, not breakdowns.

### Cross-provider note

Step 1 is implemented for the PostHog source (the active provider). `UmamiClient`'s batch
path still fills `[]` on failure and needs the same error-vs-empty treatment before the
Step 2 retry logic is correct for Umami.

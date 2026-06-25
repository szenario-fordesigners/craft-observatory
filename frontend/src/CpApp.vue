<script setup lang="ts">
import LineChart from '@/components/LineChart.vue';
import DateRangeSelector from '@/components/DateRangeSelector.vue';
import HeatmapChart from '@/components/HeatmapChart.vue';
import MetricList from '@/components/MetricList.vue';
import StatsOverview from '@/components/StatsOverview.vue';
import StatusNotice from '@/shared/StatusNotice.vue';
import type { AnalyticsStatus } from '@/shared/analyticsTypes';
import { useDateRange, type RangeValue } from '@/composables/useDateRange';
import type { AnalyticsMetric, AnalyticsPageviews, AnalyticsStats, SyncFreshness } from '@/shared/analyticsTypes';
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps<{
  title?: string;
  pageviews?: AnalyticsPageviews | null;
  defaultPeriod?: string;
}>();

const { currentRangeValue, currentRange, customRange, setCustomRange } = useDateRange(
  (props.defaultPeriod as RangeValue) || '24h',
);
const currentData = ref<AnalyticsPageviews | null>(props.pageviews ?? null);

const statsData = ref<AnalyticsStats | null>(null);
const statsLoading = ref(false);

const pageTab = ref<'url' | 'entry' | 'exit'>('url');
const sourceTab = ref<'referrer' | 'channel'>('referrer');
const envTab = ref<'browser' | 'os' | 'device'>('browser');
const locTab = ref<'country' | 'region' | 'city'>('country');

const metricsData = ref<Record<string, AnalyticsMetric[]>>({});
const metricsLoading = ref(false);

const dashboardStatus = ref<AnalyticsStatus | null>(null);
const heatmapStatus = ref<AnalyticsStatus | null>(null);
const dashboardFreshness = ref<SyncFreshness | null>(null);

// Show whichever status surfaced an error (dashboard fires first; heatmap is independent).
const status = computed<AnalyticsStatus | null>(() => {
  for (const s of [dashboardStatus.value, heatmapStatus.value]) {
    if (s && (!s.configured || !s.apiKeyValid)) return s;
  }
  return dashboardStatus.value ?? heatmapStatus.value;
});

interface DashboardDataResponse {
  pageviews?: AnalyticsPageviews | null;
  stats?: AnalyticsStats | null;
  metrics?: Record<string, AnalyticsMetric[]>;
  _syncing?: boolean;
  lastSyncedAt?: string | null;
  missingDays?: string[];
  _status?: AnalyticsStatus;
}

let dashboardAbortController: AbortController | null = null;
let dashboardRequestId = 0;
let dashboardPollTimer: ReturnType<typeof setInterval> | null = null;

const stopDashboardPolling = () => {
  if (dashboardPollTimer) {
    clearInterval(dashboardPollTimer);
    dashboardPollTimer = null;
  }
};

const freshnessMessage = computed(() => {
  const freshness = dashboardFreshness.value;
  if (!freshness) return null;

  const missingCount = freshness.missingDays.length;
  const dayLabel = missingCount === 1 ? 'day' : 'days';

  if (freshness._syncing) {
    return missingCount > 0
      ? `Historical data is still syncing (${missingCount} ${dayLabel} incomplete). This page will refresh automatically.`
      : 'Historical data is still syncing. This page will refresh automatically.';
  }

  if (missingCount > 0) {
    return `Historical data is incomplete for ${missingCount} ${dayLabel}. Sync retries may be exhausted; check the Observatory logs or queue.`;
  }

  return null;
});

const fetchDashboardData = async (includePageviews = true, showLoading = true) => {
  dashboardAbortController?.abort();

  const requestId = ++dashboardRequestId;
  const abortController = new AbortController();
  dashboardAbortController = abortController;

  if (showLoading) {
    statsLoading.value = true;
    metricsLoading.value = true;
  }

  try {
    const url = new URL(
      window.Craft.getActionUrl('observatory/dashboard/get-dashboard-data'),
      window.location.origin,
    );
    url.searchParams.append('startAt', currentRange.value.startAt.toString());
    url.searchParams.append('endAt', currentRange.value.endAt.toString());
    url.searchParams.append('unit', currentRange.value.unit);
    url.searchParams.append('includePageviews', includePageviews ? '1' : '0');

    const response = await fetch(url.toString(), {
      headers: { Accept: 'application/json' },
      signal: abortController.signal,
    });

    if (response.ok) {
      const data = (await response.json()) as DashboardDataResponse;

      if (requestId !== dashboardRequestId) {
        return;
      }

      if (includePageviews) {
        currentData.value = data.pageviews ?? null;
      }

      statsData.value = data.stats ?? null;
      metricsData.value = data.metrics ?? {};
      dashboardFreshness.value = {
        _syncing: data._syncing ?? false,
        lastSyncedAt: data.lastSyncedAt ?? null,
        missingDays: data.missingDays ?? [],
      };
      dashboardStatus.value = data._status ?? null;
    }
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') {
      return;
    }

    console.error('Error fetching dashboard data', e);
  } finally {
    if (requestId === dashboardRequestId) {
      statsLoading.value = false;
      metricsLoading.value = false;

      if (dashboardAbortController === abortController) {
        dashboardAbortController = null;
      }
    }
  }
};

onUnmounted(() => {
  dashboardAbortController?.abort();
  stopDashboardPolling();
});

watch(
  () => dashboardFreshness.value?._syncing,
  (syncing) => {
    if (syncing) {
      fetch(window.Craft.getActionUrl('queue/run'), { credentials: 'include' }).catch(() => {});
      if (!dashboardPollTimer) {
        dashboardPollTimer = setInterval(() => fetchDashboardData(true, false), 5000);
      }
    } else {
      stopDashboardPolling();
    }
  },
);

interface HeatmapData {
  cells: { weekday: number; hour: number; visitors: number }[];
  maxVisitors: number;
  daysWithData: number;
  _syncing?: boolean;
  _status?: AnalyticsStatus;
}

const heatmapData = ref<HeatmapData | null>(null);
const heatmapLoading = ref(false);
let heatmapPollTimer: ReturnType<typeof setInterval> | null = null;

const heatmapFreshnessMessage = computed(() =>
  heatmapData.value?._syncing
    ? 'Heatmap history is still syncing. This page will refresh it automatically.'
    : null,
);

const cpFreshnessMessage = computed(() => freshnessMessage.value ?? heatmapFreshnessMessage.value);

const stopHeatmapPolling = () => {
  if (heatmapPollTimer) {
    clearInterval(heatmapPollTimer);
    heatmapPollTimer = null;
  }
};

const fetchHeatmapData = async (showLoading = true) => {
  if (showLoading) {
    heatmapLoading.value = true;
  }
  try {
    const url = window.Craft.getActionUrl('observatory/dashboard/get-heatmap-data');
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (response.ok) {
      const json = (await response.json()) as HeatmapData;
      heatmapData.value = json;
      heatmapStatus.value = json._status ?? null;
    }
  } catch (e) {
    console.error('Error fetching heatmap data', e);
  } finally {
    heatmapLoading.value = false;
  }
};

onMounted(fetchHeatmapData);

watch(
  () => heatmapData.value?._syncing,
  (syncing) => {
    if (syncing) {
      fetch(window.Craft.getActionUrl('queue/run'), { credentials: 'include' }).catch(() => {});
      if (!heatmapPollTimer) {
        heatmapPollTimer = setInterval(() => fetchHeatmapData(false), 5000);
      }
    } else {
      stopHeatmapPolling();
    }
  },
);

onUnmounted(stopHeatmapPolling);

watch(
  () => [currentRange.value.startAt, currentRange.value.endAt, currentRange.value.unit] as const,
  (_newVal, oldVal) => {
    fetchDashboardData(oldVal !== undefined || !currentData.value);
  },
  { immediate: true },
);
</script>

<template>
  <div id="observatory-wrapper" class="rounded-lg border border-gray-200 bg-white p-6">
    <StatusNotice :status="status" variant="cp" />

    <div
      v-if="cpFreshnessMessage"
      class="mb-4 rounded border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"
    >
      {{ cpFreshnessMessage }}
    </div>

    <div class="mb-6 flex items-center justify-between">
      <h1 class="m-0 text-xl font-bold text-gray-800">{{ title }}</h1>
      <DateRangeSelector
        v-model="currentRangeValue"
        :custom-range="customRange"
        @update:custom-range="setCustomRange"
      />
    </div>

    <!-- KPI Stats -->
    <StatsOverview :stats="statsData" :loading="statsLoading" />

    <!-- Main Chart -->
    <div class="mb-8 rounded border border-gray-100 bg-gray-50 px-2 pt-4 pb-0">
      <div v-if="currentData">
        <LineChart :pageviews="currentData" />
      </div>
      <div v-else class="flex h-48 items-center justify-center text-gray-400">Loading data...</div>
    </div>

    <!-- Heatmap: traffic by hour of day -->
    <div class="mb-8 rounded border border-gray-100 bg-gray-50 p-4">
      <h2 class="mb-3 text-sm font-semibold text-gray-700">Traffic by hour of day</h2>
      <HeatmapChart
        :cells="heatmapData?.cells ?? []"
        :max-visitors="heatmapData?.maxVisitors ?? 0"
        :days-with-data="heatmapData?.daysWithData ?? 0"
        :loading="heatmapLoading"
      />
    </div>

    <!-- Grid Layout for Metrics -->
    <div class="grid grid-cols-1 gap-x-12 gap-y-8 md:grid-cols-2">
      <!-- Left Column: Pages & Sources -->
      <div class="space-y-8">
        <!-- Pages Group -->
        <div>
          <div class="mb-2 flex space-x-6 border-b border-gray-200 pb-2">
            <button
              @click="pageTab = 'url'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                pageTab === 'url'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Path
            </button>
            <button
              @click="pageTab = 'entry'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                pageTab === 'entry'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Entry page
            </button>
            <button
              @click="pageTab = 'exit'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                pageTab === 'exit'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Exit page
            </button>
          </div>
          <MetricList :data="metricsData[pageTab] ?? []" :loading="metricsLoading" />
        </div>

        <!-- Sources Group -->
        <div>
          <div class="mb-2 flex space-x-6 border-b border-gray-200 pb-2">
            <button
              @click="sourceTab = 'referrer'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                sourceTab === 'referrer'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Referrers
            </button>
            <button
              @click="sourceTab = 'channel'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                sourceTab === 'channel'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Channels
            </button>
          </div>
          <MetricList :data="metricsData[sourceTab] ?? []" :loading="metricsLoading" />
        </div>
      </div>

      <!-- Right Column: Environment & Location -->
      <div class="space-y-8">
        <!-- Environment Group -->
        <div>
          <div class="mb-2 flex space-x-6 border-b border-gray-200 pb-2">
            <button
              @click="envTab = 'browser'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                envTab === 'browser'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Browser
            </button>
            <button
              @click="envTab = 'os'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                envTab === 'os'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              OS
            </button>
            <button
              @click="envTab = 'device'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                envTab === 'device'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Device
            </button>
          </div>
          <MetricList :data="metricsData[envTab] ?? []" :loading="metricsLoading" />
        </div>

        <!-- Location Group -->
        <div>
          <div class="mb-2 flex space-x-6 border-b border-gray-200 pb-2">
            <button
              @click="locTab = 'country'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                locTab === 'country'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Country
            </button>
            <button
              @click="locTab = 'region'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                locTab === 'region'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              Region
            </button>
            <button
              @click="locTab = 'city'"
              :class="[
                'flex-1 text-center text-sm font-semibold',
                locTab === 'city'
                  ? '-mb-2.5 border-b-2 border-gray-900 text-gray-900'
                  : 'text-gray-500 hover:text-gray-700',
              ]"
            >
              City
            </button>
          </div>
          <MetricList :data="metricsData[locTab] ?? []" :loading="metricsLoading" />
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped></style>

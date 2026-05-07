<script setup lang="ts">
import LineChart from '@/components/LineChart.vue';
import DateRangeSelector from '@/components/DateRangeSelector.vue';
import HeatmapChart from '@/components/HeatmapChart.vue';
import MetricList from '@/components/MetricList.vue';
import StatsOverview from '@/components/StatsOverview.vue';
import StatusNotice, { type UmamiStatus } from '@/shared/StatusNotice.vue';
import { useDateRange, type RangeValue } from '@/composables/useDateRange';
import type { WebsitePageviews, WebsiteMetric, WebsiteStats } from '@umami/api-client';
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';

const props = defineProps<{
  title?: string;
  pageviews?: WebsitePageviews | null;
  defaultPeriod?: string;
}>();

const { currentRangeValue, currentRange, customRange, setCustomRange } = useDateRange(
  (props.defaultPeriod as RangeValue) || '24h',
);
const currentData = ref<WebsitePageviews | null>(props.pageviews ?? null);

const statsData = ref<WebsiteStats | null>(null);
const statsLoading = ref(false);

const pageTab = ref<'url' | 'entry' | 'exit'>('url');
const sourceTab = ref<'referrer' | 'channel'>('referrer');
const envTab = ref<'browser' | 'os' | 'device'>('browser');
const locTab = ref<'country' | 'region' | 'city'>('country');

const metricsData = ref<Record<string, WebsiteMetric[]>>({});
const metricsLoading = ref(false);

const dashboardStatus = ref<UmamiStatus | null>(null);
const heatmapStatus = ref<UmamiStatus | null>(null);

// Show whichever status surfaced an error (dashboard fires first; heatmap is independent).
const status = computed<UmamiStatus | null>(() => {
  for (const s of [dashboardStatus.value, heatmapStatus.value]) {
    if (s && (!s.configured || !s.apiKeyValid)) return s;
  }
  return dashboardStatus.value ?? heatmapStatus.value;
});

interface DashboardDataResponse {
  pageviews?: WebsitePageviews | null;
  stats?: WebsiteStats | null;
  metrics?: Record<string, WebsiteMetric[]>;
  _status?: UmamiStatus;
}

let dashboardAbortController: AbortController | null = null;
let dashboardRequestId = 0;

const fetchDashboardData = async (includePageviews = true) => {
  dashboardAbortController?.abort();

  const requestId = ++dashboardRequestId;
  const abortController = new AbortController();
  dashboardAbortController = abortController;

  statsLoading.value = true;
  metricsLoading.value = true;

  try {
    const url = new URL(
      window.Craft.getActionUrl('umami-is/dashboard/get-dashboard-data'),
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
});

interface HeatmapData {
  cells: { weekday: number; hour: number; visitors: number }[];
  maxVisitors: number;
  daysWithData: number;
  _status?: UmamiStatus;
}

const heatmapData = ref<HeatmapData | null>(null);
const heatmapLoading = ref(false);

const fetchHeatmapData = async () => {
  heatmapLoading.value = true;
  try {
    const url = window.Craft.getActionUrl('umami-is/dashboard/get-heatmap-data');
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
  () => [currentRange.value.startAt, currentRange.value.endAt, currentRange.value.unit] as const,
  (_newVal, oldVal) => {
    fetchDashboardData(oldVal !== undefined || !currentData.value);
  },
  { immediate: true },
);
</script>

<template>
  <div id="umami-is-wrapper" class="rounded-lg border border-gray-200 bg-white p-6">
    <StatusNotice :status="status" variant="cp" />

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

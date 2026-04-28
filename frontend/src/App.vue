<script setup lang="ts">
import LineChartDemo from '@/components/LineChartDemo.vue';
import DateRangeSelector from '@/components/DateRangeSelector.vue';
import MetricList from '@/components/MetricList.vue';
import StatsOverview from '@/components/StatsOverview.vue';
import { useDateRange, type RangeValue } from '@/composables/useDateRange';
import type { WebsitePageviews, WebsiteMetric, WebsiteStats } from '@umami/api-client';
import { ref, onUnmounted, watch } from 'vue';

const props = defineProps<{
  title: string;
  pageviews: WebsitePageviews | null;
  defaultPeriod?: string;
}>();

const { currentRangeValue, currentRange } = useDateRange((props.defaultPeriod as RangeValue) || '24h');
const currentData = ref<WebsitePageviews | null>(props.pageviews);

const statsData = ref<WebsiteStats | null>(null);
const statsLoading = ref(false);

const envTab = ref<'browser' | 'os' | 'device'>('browser');
const locTab = ref<'country' | 'region' | 'city'>('country');

const metricsData = ref<Record<string, WebsiteMetric[]>>({});
const metricsLoading = ref(false);

interface DashboardDataResponse {
  pageviews?: WebsitePageviews | null;
  stats?: WebsiteStats | null;
  metrics?: Record<string, WebsiteMetric[]>;
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
    const url = new URL(window.Craft.getActionUrl('umami-is/dashboard/get-dashboard-data'), window.location.origin);
    url.searchParams.append('startAt', currentRange.value.startAt.toString());
    url.searchParams.append('endAt', currentRange.value.endAt.toString());
    url.searchParams.append('unit', currentRange.value.unit);
    url.searchParams.append('includePageviews', includePageviews ? '1' : '0');

    const response = await fetch(url.toString(), {
      headers: { 'Accept': 'application/json' },
      signal: abortController.signal,
    });

    if (response.ok) {
      const data = await response.json() as DashboardDataResponse;

      if (requestId !== dashboardRequestId) {
        return;
      }

      if (includePageviews) {
        currentData.value = data.pageviews ?? null;
      }

      statsData.value = data.stats ?? null;
      metricsData.value = data.metrics ?? {};
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

watch(currentRangeValue, (newVal, oldVal) => {
  if (newVal !== oldVal) {
    fetchDashboardData(oldVal !== undefined || !currentData.value);
  }
}, { immediate: true });
</script>

<template>
  <div id="umami-is-wrapper" class="bg-white p-6 rounded-lg border border-gray-200">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-xl text-gray-800 font-bold m-0">{{ title }}</h1>
      <DateRangeSelector v-model="currentRangeValue" />
    </div>

    <!-- KPI Stats -->
    <StatsOverview :stats="statsData" :loading="statsLoading" />

    <!-- Main Chart -->
    <div class="mb-8 pt-4 pb-0 px-2 bg-gray-50 rounded border border-gray-100">
      <div v-if="currentData">
        <LineChartDemo :pageviews="currentData" />
      </div>
      <div v-else class="h-48 flex items-center justify-center text-gray-400">
        Loading data...
      </div>
    </div>

    <!-- Grid Layout for Metrics -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-x-12 gap-y-8">
      
      <!-- Left Column: Pages & Sources -->
      <div class="space-y-8">
        <div>
           <MetricList label="Visitors per Page" :data="metricsData['url'] ?? []" :loading="metricsLoading" />
        </div>
        <div>
           <MetricList label="Sources" :data="metricsData['referrer'] ?? []" :loading="metricsLoading" />
        </div>
      </div>
      
      <!-- Right Column: Environment & Location -->
      <div class="space-y-8">
        <!-- Environment Group -->
        <div>
          <div class="flex space-x-6 mb-2 border-b border-gray-200 pb-2">
            <button 
              @click="envTab = 'browser'" 
              :class="['text-sm font-semibold flex-1 text-center', envTab === 'browser' ? 'text-gray-900 border-b-2 border-gray-900 -mb-[10px]' : 'text-gray-500 hover:text-gray-700']">
              Browser
            </button>
            <button 
              @click="envTab = 'os'" 
              :class="['text-sm font-semibold flex-1 text-center', envTab === 'os' ? 'text-gray-900 border-b-2 border-gray-900 -mb-[10px]' : 'text-gray-500 hover:text-gray-700']">
              OS
            </button>
            <button 
              @click="envTab = 'device'" 
              :class="['text-sm font-semibold flex-1 text-center', envTab === 'device' ? 'text-gray-900 border-b-2 border-gray-900 -mb-[10px]' : 'text-gray-500 hover:text-gray-700']">
              Device
            </button>
          </div>
          <MetricList :data="metricsData[envTab] ?? []" :loading="metricsLoading" />
        </div>
        
        <!-- Location Group -->
        <div>
          <div class="flex space-x-6 mb-2 border-b border-gray-200 pb-2">
            <button 
              @click="locTab = 'country'" 
              :class="['text-sm font-semibold flex-1 text-center', locTab === 'country' ? 'text-gray-900 border-b-2 border-gray-900 -mb-[10px]' : 'text-gray-500 hover:text-gray-700']">
              Country
            </button>
            <button 
              @click="locTab = 'region'" 
              :class="['text-sm font-semibold flex-1 text-center', locTab === 'region' ? 'text-gray-900 border-b-2 border-gray-900 -mb-[10px]' : 'text-gray-500 hover:text-gray-700']">
              Region
            </button>
            <button 
              @click="locTab = 'city'" 
              :class="['text-sm font-semibold flex-1 text-center', locTab === 'city' ? 'text-gray-900 border-b-2 border-gray-900 -mb-[10px]' : 'text-gray-500 hover:text-gray-700']">
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

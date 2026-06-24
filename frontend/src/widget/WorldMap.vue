<script setup lang="ts">
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CountryMap from '@/shared/CountryMap.vue';
import StatusNotice from '@/shared/StatusNotice.vue';
import type { AnalyticsStatus } from '@/shared/analyticsTypes';
import { useWidgetData } from '@/shared/useWidgetData';

interface MetricEntry {
  x: string;
  y: number;
}

interface MetricsResponse {
  data: MetricEntry[];
  _status?: AnalyticsStatus;
}

defineProps<{
  locale?: string;
}>();

const hasError = (s: AnalyticsStatus | undefined): boolean => !!s && (!s.configured || !s.apiKeyValid);

const { data } = useWidgetData<MetricsResponse>('observatory/dashboard/get-metrics?type=country');
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
      <div class="observatory-world-map__head">
        <div class="observatory-world-map__col">
          <div class="observatory-world-map__header">visitors by country</div>
          <div class="observatory-world-map__subheader">last 7 days</div>
        </div>
      </div>

      <hr class="observatory-widget__divider" />

      <CountryMap :countries="data?.data" :locale="locale" />
    </template>
  </WidgetFrame>
</template>

<style scoped>
.observatory-world-map__head {
  display: flex;
  justify-content: space-between;
  align-items: start;
}

.observatory-world-map__col {
  display: flex;
  flex-direction: column;
}

.observatory-world-map__header {
  font-size: 1.25rem;
  margin-bottom: 0.25rem;
}

.observatory-world-map__subheader {
  font-size: 0.95rem;
  opacity: 0.7;
}
</style>

<style>
div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryWorldMapWidget'] .widget-heading {
  display: none;
}
</style>

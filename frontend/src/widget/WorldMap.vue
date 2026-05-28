<script setup lang="ts">
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CountryMap from '@/shared/CountryMap.vue';
import StatusNotice, { type UmamiStatus } from '@/shared/StatusNotice.vue';
import { useWidgetData } from '@/shared/useWidgetData';

interface MetricEntry {
  x: string;
  y: number;
}

interface MetricsResponse {
  data: MetricEntry[];
  _status?: UmamiStatus;
}

defineProps<{
  locale?: string;
}>();

const hasError = (s: UmamiStatus | undefined): boolean => !!s && (!s.configured || !s.apiKeyValid);

const { data } = useWidgetData<MetricsResponse>('umami-is/dashboard/get-metrics?type=country');
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
      <div class="umami-world-map__head">
        <div class="umami-world-map__col">
          <div class="umami-world-map__header">visitors by country</div>
          <div class="umami-world-map__subheader">last 7 days</div>
        </div>
      </div>

      <hr class="umami-widget__divider" />

      <CountryMap :countries="data?.data" :locale="locale" />
    </template>
  </WidgetFrame>
</template>

<style scoped>
.umami-world-map__head {
  display: flex;
  justify-content: space-between;
  align-items: start;
}

.umami-world-map__col {
  display: flex;
  flex-direction: column;
}

.umami-world-map__header {
  font-size: 1.25rem;
  margin-bottom: 0.25rem;
}

.umami-world-map__subheader {
  font-size: 0.95rem;
  opacity: 0.7;
}
</style>

<style>
div[data-type='szenario\\craftumamiis\\widgets\\UmamiIsWorldMapWidget'] .widget-heading {
  display: none;
}
</style>

<script setup lang="ts">
import { computed, ref, onMounted, onBeforeUnmount } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import StatusNotice, { type UmamiStatus } from '@/shared/StatusNotice.vue';
import { useWidgetData } from '@/shared/useWidgetData';
import { VisSingleContainer, VisTopoJSONMap, VisTooltip } from '@unovis/vue';
import { WorldMapTopoJSON } from '@unovis/ts/maps';
import { TopoJSONMap } from '@unovis/ts';

interface MetricEntry {
  x: string;
  y: number;
}

interface MetricsResponse {
  data: MetricEntry[];
  _status?: UmamiStatus;
}

interface MapArea {
  id: string;
  y: number;
}

const props = defineProps<{
  locale?: string;
}>();

const hasError = (s: UmamiStatus | undefined): boolean =>
  !!s && (!s.configured || !s.apiKeyValid);

const { data } = useWidgetData<MetricsResponse>('umami-is/dashboard/get-metrics?type=country');

const maxVisitors = computed(() => {
  if (!data.value?.data || data.value.data.length === 0) return 0;
  return Math.max(0, ...data.value.data.map((d) => d.y));
});

const mapData = computed((): MapArea[] => {
  const geometries = (WorldMapTopoJSON as any).objects?.countries?.geometries || [];
  const metrics = data.value?.data || [];
  return geometries.map((geo: any) => {
    const metric = metrics.find((m) => m.x === geo.id);
    return { id: geo.id as string, y: metric ? metric.y : 0 };
  });
});

const areaId = (d: MapArea) => d.id;

const areaColor = (d: MapArea | undefined) => {
  if (!d || d.y === 0 || maxVisitors.value === 0) {
    return 'color-mix(in srgb, var(--umami-fg) 10%, transparent)';
  }
  const ratio = 0.3 + (d.y / maxVisitors.value) * 0.7;
  return `color-mix(in srgb, var(--umami-fg) ${Math.round(ratio * 100)}%, transparent)`;
};

const regionNames = new Intl.DisplayNames([props.locale || 'en'], { type: 'region' });

const tooltipTriggers = {
  [TopoJSONMap.selectors.feature]: (d: any) => {
    const code = d?.id;
    let name = code || 'Unknown';
    if (code) {
      try { name = regionNames.of(code) || code; } catch { name = code; }
    }
    const visitors = (data.value?.data || []).find(m => m.x === code)?.y ?? 0;
    return `${name}: ${visitors} visitors`;
  },
};

// Ref is on the outer container (always rendered) so ResizeObserver fires immediately
const mapContainerRef = ref<HTMLElement | null>(null);
const containerWidth = ref(0);
let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
  if (!mapContainerRef.value) return;
  resizeObserver = new ResizeObserver(entries => {
    containerWidth.value = entries[0]?.contentRect.width ?? 0;
  });
  resizeObserver.observe(mapContainerRef.value);
  containerWidth.value = mapContainerRef.value.clientWidth;
});

onBeforeUnmount(() => {
  resizeObserver?.disconnect();
});
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

    <div ref="mapContainerRef" class="umami-world-map__map-container">
      <CrossFade>
        <div v-if="data" key="real-map" class="umami-world-map__map-inner">
          <VisSingleContainer :data="{ areas: mapData }" :width="containerWidth || undefined">
            <VisTopoJSONMap
              :topojson="WorldMapTopoJSON"
              :areaId="areaId"
              :areaColor="areaColor"
              :strokeWidth="0.5"
              strokeColor="var(--umami-bg)"
            />
            <VisTooltip :triggers="tooltipTriggers" />
          </VisSingleContainer>
        </div>
        <div v-else key="skeleton-map" class="umami-world-map__skeleton">
           <div class="umami-world-map__skeleton-inner"></div>
        </div>
      </CrossFade>
    </div>
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

.umami-world-map__map-container {
  width: 100%;
  aspect-ratio: 2 / 1;
  position: relative;
  display: flex;
  flex-direction: column;
}

.umami-world-map__map-inner {
  flex: 1;
  display: flex;
  width: 100%;
  height: 100%;
}

.umami-world-map__skeleton {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.umami-world-map__skeleton-inner {
  width: 100%;
  height: 100%;
  background-color: color-mix(in srgb, var(--umami-fg) 10%, transparent);
  border-radius: 4px;
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

:deep(.vis-topojson-map) {
  --vis-map-feature-color: color-mix(in srgb, var(--umami-fg) 10%, transparent);
}

:deep(.vis-topojson-map path) {
  fill: color-mix(in srgb, var(--umami-fg) 10%, transparent);
  transition: fill 0.3s ease;
}

:deep(.vis-topojson-map path:hover) {
  fill: var(--umami-fg) !important;
}
</style>

<style>
/* Global styles for tooltips since they are appended to the body by Unovis */
.vis-tooltip {
  background-color: var(--umami-fg) !important;
  color: var(--umami-bg) !important;
  border-radius: 4px;
  padding: 4px 8px;
  font-size: 0.85rem;
  border: none !important;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}

div[data-type='szenario\\craftumamiis\\widgets\\UmamiIsWorldMapWidget'] .widget-heading {
  display: none;
}
</style>

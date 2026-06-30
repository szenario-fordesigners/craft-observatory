<script setup lang="ts">
import { computed, ref, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
import CrossFade from '@/shared/CrossFade.vue';
import { VisSingleContainer, VisTopoJSONMap, VisTooltip } from '@unovis/vue';
import { WorldMapTopoJSON } from '@unovis/ts/maps';
import { TopoJSONMap } from '@unovis/ts';
import { resolveCountryName } from '@/shared/resolveCountryName';

interface MetricEntry {
  x: string;
  y: number;
}

interface MapArea {
  id: string;
  y: number;
}

interface TopoJSONGeometry {
  id: string;
}
interface TopoJSONTopology {
  objects?: { countries?: { geometries?: TopoJSONGeometry[] } };
}

const props = defineProps<{
  /** Country metric entries (`[{x: country-code, y: visitors}, ...]`).
      Pass `null` / `undefined` to show the loading skeleton. */
  countries: MetricEntry[] | null | undefined;
  locale?: string;
}>();

const maxVisitors = computed(() => {
  if (!props.countries || props.countries.length === 0) return 0;
  return Math.max(0, ...props.countries.map((d) => d.y));
});

const mapData = computed((): MapArea[] => {
  const geometries = (WorldMapTopoJSON as TopoJSONTopology).objects?.countries?.geometries || [];
  const metrics = props.countries || [];
  return geometries.map((geo) => {
    const metric = metrics.find((m) => m.x === geo.id);
    return { id: geo.id, y: metric ? metric.y : 0 };
  });
});

const areaId = (d: MapArea) => d.id;

const areaColor = (d: MapArea | undefined) => {
  if (!d || d.y === 0 || maxVisitors.value === 0) {
    return 'color-mix(in srgb, var(--observatory-fg) 10%, transparent)';
  }
  // Square-root scale: visitor data is heavily right-skewed, so a linear ramp would
  // leave the long tail of low-visitor countries nearly invisible.
  const ratio = 0.15 + Math.sqrt(d.y / maxVisitors.value) * 0.85;
  return `color-mix(in srgb, var(--observatory-fg) ${Math.round(ratio * 100)}%, transparent)`;
};

const tooltipTriggers = {
  [TopoJSONMap.selectors.feature]: (d: { id?: string }) => {
    const code = d?.id;
    const name = resolveCountryName(code, props.locale, 'Unknown');
    const visitors = (props.countries || []).find((m) => m.x === code)?.y ?? 0;
    return `${name}: ${visitors} visitors`;
  },
};

// The Unovis Vue SingleContainer wrapper calls `setData(data, preventRender=true)` on
// data changes, so updated metrics are stored but the area fills are never repainted
// (the tooltip still reads live props, which is why it stays correct). Force a re-render
// of the map component whenever the data changes.
const mapRef = ref<{ component?: { render: () => void } } | null>(null);

watch(
  () => props.countries,
  async () => {
    await nextTick();
    mapRef.value?.component?.render();
  },
);

// Ref is on the outer container (always rendered) so ResizeObserver fires immediately,
// regardless of whether the skeleton or the real map is mounted inside.
const containerRef = ref<HTMLElement | null>(null);
const containerWidth = ref(0);
let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
  if (!containerRef.value) return;
  resizeObserver = new ResizeObserver((entries) => {
    containerWidth.value = entries[0]?.contentRect.width ?? 0;
  });
  resizeObserver.observe(containerRef.value);
  containerWidth.value = containerRef.value.clientWidth;
});

onBeforeUnmount(() => {
  resizeObserver?.disconnect();
});
</script>

<template>
  <div ref="containerRef" class="observatory-country-map">
    <CrossFade>
      <div v-if="countries" key="real-map" class="observatory-country-map__inner">
        <VisSingleContainer :data="{ areas: mapData }" :width="containerWidth || undefined">
          <VisTopoJSONMap
            ref="mapRef"
            :topojson="WorldMapTopoJSON"
            :areaId="areaId"
            :areaColor="areaColor"
            mapFeatureDefaultColor="color-mix(in srgb, var(--observatory-fg) 10%, transparent)"
            :strokeWidth="0.5"
            strokeColor="var(--observatory-bg)"
          />
          <VisTooltip :triggers="tooltipTriggers" />
        </VisSingleContainer>
      </div>
      <div v-else key="skeleton-map" class="observatory-country-map__skeleton">
        <div class="observatory-country-map__skeleton-inner"></div>
      </div>
    </CrossFade>
  </div>
</template>

<style scoped>
.observatory-country-map {
  --vis-map-feature-color: color-mix(in srgb, var(--observatory-fg) 10%, transparent);
  width: 100%;
  aspect-ratio: 2 / 1;
  position: relative;
  display: flex;
  flex-direction: column;
}

.observatory-country-map__inner {
  flex: 1;
  display: flex;
  width: 100%;
  height: 100%;
}

.observatory-country-map__skeleton {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
}

.observatory-country-map__skeleton-inner {
  width: 100%;
  height: 100%;
  background-color: color-mix(in srgb, var(--observatory-fg) 10%, transparent);
  border-radius: 4px;
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

:deep(.vis-topojson-map) {
  --vis-map-feature-color: color-mix(in srgb, var(--observatory-fg) 10%, transparent);
}

:deep(.vis-topojson-map path) {
  fill: color-mix(in srgb, var(--observatory-fg) 10%, transparent);
  transition: fill 0.3s ease;
}

:deep(.vis-topojson-map path:hover) {
  fill: var(--observatory-fg) !important;
}
</style>

<style>
/* Unovis appends tooltips to <body>, so they must be styled globally. */
.vis-tooltip {
  background-color: var(--observatory-fg) !important;
  color: var(--observatory-bg) !important;
  border-radius: 4px;
  padding: 4px 8px;
  font-size: 0.85rem;
  border: none !important;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
}
</style>

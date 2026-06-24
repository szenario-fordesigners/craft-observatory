<script setup lang="ts">
import { computed } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import SkeletonText from '@/shared/SkeletonText.vue';
import StatusNotice from '@/shared/StatusNotice.vue';
import type { AnalyticsStatus } from '@/shared/analyticsTypes';
import { useWidgetData } from '@/shared/useWidgetData';

const props = defineProps<{
  locale?: string;
}>();

interface MetricEntry {
  x: string;
  y: number;
}

interface MetricsResponse {
  data: MetricEntry[];
  _status?: AnalyticsStatus;
}

const hasError = (s: AnalyticsStatus | undefined): boolean =>
  !!s && (!s.configured || !s.apiKeyValid);

const { data, loading, error } = useWidgetData<MetricsResponse>('observatory/dashboard/get-metrics?type=country');

import { resolveCountryName } from '@/shared/resolveCountryName';

const getCountryName = (code: string) => resolveCountryName(code, props.locale, '—');

const getFlagUrl = (code: string) => {
  if (!code || code === 'Unknown') return ''; // Or a fallback generic icon if you have one
  return `https://flagcdn.com/w20/${code.toLowerCase()}.png`;
};

// Limit the number of countries to display so it doesn't get too long
const displayedCountries = computed(() => {
  if (!data.value?.data) return [];
  return [...data.value.data].sort((a, b) => b.y - a.y).slice(0, 5);
});

// Provide a unified list of either real items or fake items for skeleton loading
const listItems = computed(() => {
  if (loading.value && (!data.value?.data || data.value.data.length === 0)) {
    return Array.from({ length: 5 }).map((_, i) => ({
      x: `skel-${i}`,
      y: 0,
      isSkeleton: true
    }));
  }
  return displayedCountries.value.map(item => ({
    ...item,
    isSkeleton: false
  }));
});

const maxVisitors = computed(() => {
  if (!data.value?.data || data.value.data.length === 0) return 0;
  return Math.max(0, ...data.value.data.map((d) => d.y));
});

// Stagger delays for a nice cascade effect
const getSkeletonDelay = (index: number) => `${index * 100}ms`;
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
    <div class="observatory-countries__head">
      <div class="observatory-countries__col">
        <div class="observatory-countries__header">top countries</div>
        <div class="observatory-countries__subheader">last 7 days</div>
      </div>
    </div>

    <hr class="observatory-widget__divider" />

    <div class="observatory-countries__list-container">
      <div v-if="error" class="observatory-countries__error">
        Failed to load countries
      </div>
      <div v-else-if="!loading && listItems.length === 0" class="observatory-countries__empty">
        No countries found
      </div>
      <div v-else class="observatory-countries__list">
        <div
          v-for="(item, index) in listItems"
          :key="item.x"
          class="observatory-countries__item"
          :class="{ 'observatory-countries__item--real': !item.isSkeleton }"
          :style="!item.isSkeleton ? { animationDelay: getSkeletonDelay(index) } : undefined"
        >
          <!-- Background bar -->
          <div
            class="observatory-countries__bar-bg"
            :class="{ 'observatory-countries__bar-bg--skeleton': item.isSkeleton }"
            :style="{
              width: item.isSkeleton ? '100%' : `${(item.y / maxVisitors) * 100}%`,
              animationDelay: item.isSkeleton ? getSkeletonDelay(index) : undefined
            }"
          ></div>

          <div class="observatory-countries__item-content">
            <div class="observatory-countries__domain-group">
              <CrossFade>
                <div
                  v-if="item.isSkeleton"
                  key="skel-icon"
                  class="observatory-countries__skeleton-icon"
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                ></div>
                <img
                  v-else-if="getFlagUrl(item.x)"
                  key="real-icon"
                  :src="getFlagUrl(item.x)"
                  class="observatory-countries__favicon"
                  alt=""
                  loading="lazy"
                  @error="($event.target as HTMLImageElement).style.display='none'"
                />
                <div
                  v-else
                  key="no-icon"
                  class="observatory-countries__no-icon"
                ></div>
              </CrossFade>

              <CrossFade>
                <SkeletonText
                  v-if="item.isSkeleton"
                  key="skel-text"
                  style="width: 140px;"
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                />
                <span v-else key="real-text" class="observatory-countries__domain">{{ getCountryName(item.x) }}</span>
              </CrossFade>
            </div>

            <CrossFade>
              <SkeletonText
                v-if="item.isSkeleton"
                key="skel-val"
                style="width: 30px;"
                :style="{ animationDelay: getSkeletonDelay(index) }"
              />
              <span v-else key="real-val" class="observatory-countries__visitors">{{ item.y }}</span>
            </CrossFade>
          </div>
        </div>
      </div>
    </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.pane hr.observatory-widget__divider {
  margin-block: 1rem;
}

.observatory-countries__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.observatory-countries__col {
  display: flex;
  flex-direction: column;
}

.observatory-countries__header {
  font-size: 1.25rem;
  font-weight: 500;
  color: var(--observatory-fg);
  line-height: 1;
}

.observatory-countries__subheader {
  font-size: 0.875rem;
  color: var(--observatory-fg);
  opacity: 0.7;
  margin-top: 0.25rem;
}

.observatory-countries__list-container {
  position: relative;
  min-height: 200px;
  display: flex;
  flex-direction: column;
}

.observatory-countries__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.observatory-countries__item {
  position: relative;
  display: flex;
  align-items: center;
  min-height: 40px;
  padding: 0.5rem 0.25rem;
  border-radius: 4px;
}

/* Fade-in entrance for the real items when they replace the skeleton row.
   `backwards` holds opacity 0 during the staggered animation-delay, otherwise
   the row would flash in before its delay elapses. */
.observatory-countries__item--real {
  animation: observatory-countries-item-fade-in 0.5s ease backwards;
}

@keyframes observatory-countries-item-fade-in {
  from {
    opacity: 0;
  }
}

.observatory-countries__bar-bg {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background-color: color-mix(in srgb, var(--observatory-fg) 10%, transparent);
  border-radius: 4px;
  z-index: 0;
  transition: width 0.5s ease-out;
}

.observatory-countries__bar-bg--skeleton {
  opacity: 0.4;
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-countries__item-content {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0 0.5rem;
}

.observatory-countries__domain-group {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  overflow: hidden;
}

.observatory-countries__favicon {
  width: 20px;
  height: auto;
  object-fit: contain;
  flex-shrink: 0;
  border-radius: 2px;
}

.observatory-countries__no-icon {
  width: 20px;
  height: 14px;
  flex-shrink: 0;
}

.observatory-countries__skeleton-icon {
  width: 20px;
  height: 14px;
  border-radius: 2px;
  background-color: color-mix(in srgb, var(--observatory-fg) 20%, transparent);
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-countries__domain {
  font-size: 0.95rem;
  color: var(--observatory-fg);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.observatory-countries__visitors {
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--observatory-fg);
  padding-left: 1rem;
}

.observatory-countries__error,
.observatory-countries__empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--observatory-fg);
  opacity: 0.7;
}

@keyframes observatory-bar-pulse {
  0% { opacity: 0.4; }
  50% { opacity: 0.8; }
  100% { opacity: 0.4; }
}
</style>

<style>
div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryCountriesWidget'] .widget-heading {
  display: none;
}

div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryCountriesWidget'] .pane {
  --pane-padding: 16px;
}
</style>

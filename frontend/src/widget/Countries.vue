<script setup lang="ts">
import { computed } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import SkeletonText from '@/shared/SkeletonText.vue';
import StatusNotice, { type UmamiStatus } from '@/shared/StatusNotice.vue';
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
  _status?: UmamiStatus;
}

const hasError = (s: UmamiStatus | undefined): boolean =>
  !!s && (!s.configured || !s.apiKeyValid);

const { data, loading, error } = useWidgetData<MetricsResponse>('umami-is/dashboard/get-metrics?type=country');

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
    <div class="umami-countries__head">
      <div class="umami-countries__col">
        <div class="umami-countries__header">top countries</div>
        <div class="umami-countries__subheader">last 7 days</div>
      </div>
    </div>

    <hr class="umami-widget__divider" />

    <div class="umami-countries__list-container">
      <div v-if="error" class="umami-countries__error">
        Failed to load countries
      </div>
      <div v-else-if="!loading && listItems.length === 0" class="umami-countries__empty">
        No countries found
      </div>
      <div v-else class="umami-countries__list">
        <div
          v-for="(item, index) in listItems"
          :key="item.x"
          class="umami-countries__item"
          :class="{ 'umami-countries__item--real': !item.isSkeleton }"
          :style="!item.isSkeleton ? { animationDelay: getSkeletonDelay(index) } : undefined"
        >
          <!-- Background bar -->
          <div 
            class="umami-countries__bar-bg"
            :class="{ 'umami-countries__bar-bg--skeleton': item.isSkeleton }"
            :style="{ 
              width: item.isSkeleton ? '100%' : `${(item.y / maxVisitors) * 100}%`,
              animationDelay: item.isSkeleton ? getSkeletonDelay(index) : undefined 
            }"
          ></div>
          
          <div class="umami-countries__item-content">
            <div class="umami-countries__domain-group">
              <CrossFade>
                <div 
                  v-if="item.isSkeleton" 
                  key="skel-icon" 
                  class="umami-countries__skeleton-icon"
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                ></div>
                <img 
                  v-else-if="getFlagUrl(item.x)"
                  key="real-icon"
                  :src="getFlagUrl(item.x)" 
                  class="umami-countries__favicon" 
                  alt="" 
                  loading="lazy" 
                  @error="($event.target as HTMLImageElement).style.display='none'"
                />
                <div 
                  v-else 
                  key="no-icon" 
                  class="umami-countries__no-icon"
                ></div>
              </CrossFade>

              <CrossFade>
                <SkeletonText 
                  v-if="item.isSkeleton" 
                  key="skel-text" 
                  style="width: 140px;" 
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                />
                <span v-else key="real-text" class="umami-countries__domain">{{ getCountryName(item.x) }}</span>
              </CrossFade>
            </div>
            
            <CrossFade>
              <SkeletonText 
                v-if="item.isSkeleton" 
                key="skel-val" 
                style="width: 30px;" 
                :style="{ animationDelay: getSkeletonDelay(index) }"
              />
              <span v-else key="real-val" class="umami-countries__visitors">{{ item.y }}</span>
            </CrossFade>
          </div>
        </div>
      </div>
    </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.umami-countries__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.umami-countries__col {
  display: flex;
  flex-direction: column;
}

.umami-countries__header {
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--umami-fg);
  line-height: 1;
}

.umami-countries__subheader {
  font-size: 0.875rem;
  color: var(--umami-fg);
  opacity: 0.7;
  margin-top: 0.25rem;
}

.umami-countries__list-container {
  position: relative;
  min-height: 200px;
  display: flex;
  flex-direction: column;
}

.umami-countries__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.umami-countries__item {
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
.umami-countries__item--real {
  animation: umami-countries-item-fade-in 0.5s ease backwards;
}

@keyframes umami-countries-item-fade-in {
  from {
    opacity: 0;
  }
}

.umami-countries__bar-bg {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background-color: color-mix(in srgb, var(--umami-fg) 10%, transparent);
  border-radius: 4px;
  z-index: 0;
  transition: width 0.5s ease-out;
}

.umami-countries__bar-bg--skeleton {
  opacity: 0.4;
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-countries__item-content {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0 0.5rem;
}

.umami-countries__domain-group {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  overflow: hidden;
}

.umami-countries__favicon {
  width: 20px;
  height: auto;
  object-fit: contain;
  flex-shrink: 0;
  border-radius: 2px;
}

.umami-countries__no-icon {
  width: 20px;
  height: 14px;
  flex-shrink: 0;
}

.umami-countries__skeleton-icon {
  width: 20px;
  height: 14px;
  border-radius: 2px;
  background-color: color-mix(in srgb, var(--umami-fg) 20%, transparent);
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-countries__domain {
  font-size: 0.95rem;
  color: var(--umami-fg);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.umami-countries__visitors {
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--umami-fg);
  padding-left: 1rem;
}

.umami-countries__error,
.umami-countries__empty {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--umami-fg);
  opacity: 0.7;
}

@keyframes umami-bar-pulse {
  0% { opacity: 0.4; }
  50% { opacity: 0.8; }
  100% { opacity: 0.4; }
}
</style>

<style>
div[data-type='szenario\\craftumamiis\\widgets\\UmamiIsCountriesWidget'] .widget-heading {
  display: none;
}
</style>

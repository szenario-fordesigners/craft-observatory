<script setup lang="ts">
import { computed } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import SkeletonText from '@/shared/SkeletonText.vue';
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

const hasError = (s: AnalyticsStatus | undefined): boolean =>
  !!s && (!s.configured || !s.apiKeyValid);

const { data, loading, error } = useWidgetData<MetricsResponse>('observatory/dashboard/get-metrics?type=device');

const displayedDevices = computed(() => {
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
  return displayedDevices.value.map(item => ({
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

// Normalize device names (e.g. capitalize first letter)
const formatDeviceName = (name: string) => {
  if (!name) return 'Unknown';
  return name.charAt(0).toUpperCase() + name.slice(1).toLowerCase();
};
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
    <div class="observatory-devices__head">
      <div class="observatory-devices__col">
        <div class="observatory-devices__header">top devices</div>
        <div class="observatory-devices__subheader">last 7 days</div>
      </div>
    </div>

    <hr class="observatory-widget__divider" />

    <div class="observatory-devices__list-container">
      <div v-if="error" class="observatory-devices__error">
        Failed to load devices
      </div>
      <div v-else-if="!loading && listItems.length === 0" class="observatory-devices__empty">
        No devices found
      </div>
      <div v-else class="observatory-devices__list">
        <div
          v-for="(item, index) in listItems"
          :key="item.x"
          class="observatory-devices__item"
          :class="{ 'observatory-devices__item--real': !item.isSkeleton }"
          :style="!item.isSkeleton ? { animationDelay: getSkeletonDelay(index) } : undefined"
        >
          <!-- Background bar -->
          <div 
            class="observatory-devices__bar-bg"
            :class="{ 'observatory-devices__bar-bg--skeleton': item.isSkeleton }"
            :style="{ 
              width: item.isSkeleton ? '100%' : `${(item.y / maxVisitors) * 100}%`,
              animationDelay: item.isSkeleton ? getSkeletonDelay(index) : undefined 
            }"
          ></div>
          
          <div class="observatory-devices__item-content">
            <div class="observatory-devices__domain-group">
              <CrossFade>
                <div 
                  v-if="item.isSkeleton" 
                  key="skel-icon" 
                  class="observatory-devices__skeleton-icon"
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                ></div>
                <!-- Device SVGs based on name -->
                <svg v-else-if="item.x.toLowerCase() === 'desktop'" key="desktop" class="observatory-devices__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                  <line x1="8" y1="21" x2="16" y2="21"></line>
                  <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
                <svg v-else-if="item.x.toLowerCase() === 'laptop'" key="laptop" class="observatory-devices__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="4" width="18" height="12" rx="2" ry="2"></rect>
                  <path d="M2 20h20"></path>
                </svg>
                <svg v-else-if="item.x.toLowerCase() === 'tablet'" key="tablet" class="observatory-devices__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="4" y="2" width="16" height="20" rx="2" ry="2"></rect>
                  <line x1="12" y1="18" x2="12.01" y2="18"></line>
                </svg>
                <svg v-else-if="item.x.toLowerCase() === 'mobile'" key="mobile" class="observatory-devices__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="5" y="2" width="14" height="20" rx="2" ry="2"></rect>
                  <line x1="12" y1="18" x2="12.01" y2="18"></line>
                </svg>
                <svg v-else key="unknown" class="observatory-devices__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="2" y="3" width="20" height="14" rx="2" ry="2"></rect>
                  <line x1="8" y1="21" x2="16" y2="21"></line>
                  <line x1="12" y1="17" x2="12" y2="21"></line>
                </svg>
              </CrossFade>

              <CrossFade>
                <SkeletonText 
                  v-if="item.isSkeleton" 
                  key="skel-text" 
                  style="width: 100px;" 
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                />
                <span v-else key="real-text" class="observatory-devices__domain">{{ formatDeviceName(item.x) }}</span>
              </CrossFade>
            </div>
            
            <CrossFade>
              <SkeletonText 
                v-if="item.isSkeleton" 
                key="skel-val" 
                style="width: 30px;" 
                :style="{ animationDelay: getSkeletonDelay(index) }"
              />
              <span v-else key="real-val" class="observatory-devices__visitors">{{ item.y }}</span>
            </CrossFade>
          </div>
        </div>
      </div>
    </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.observatory-devices__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.observatory-devices__col {
  display: flex;
  flex-direction: column;
}

.observatory-devices__header {
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--observatory-fg);
  line-height: 1;
}

.observatory-devices__subheader {
  font-size: 0.875rem;
  color: var(--observatory-fg);
  opacity: 0.7;
  margin-top: 0.25rem;
}

.observatory-devices__list-container {
  position: relative;
  min-height: 200px;
  display: flex;
  flex-direction: column;
}

.observatory-devices__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.observatory-devices__item {
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
.observatory-devices__item--real {
  animation: observatory-devices-item-fade-in 0.5s ease backwards;
}

@keyframes observatory-devices-item-fade-in {
  from {
    opacity: 0;
  }
}

.observatory-devices__bar-bg {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background-color: color-mix(in srgb, var(--observatory-fg) 10%, transparent);
  border-radius: 4px;
  z-index: 0;
  transition: width 0.5s ease-out;
}

.observatory-devices__bar-bg--skeleton {
  opacity: 0.4;
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-devices__item-content {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0 0.5rem;
}

.observatory-devices__domain-group {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  overflow: hidden;
}

.observatory-devices__icon {
  width: 18px;
  height: 18px;
  color: var(--observatory-fg);
  opacity: 0.8;
  flex-shrink: 0;
}

.observatory-devices__skeleton-icon {
  width: 18px;
  height: 18px;
  border-radius: 2px;
  background-color: color-mix(in srgb, var(--observatory-fg) 20%, transparent);
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-devices__domain {
  font-size: 0.95rem;
  color: var(--observatory-fg);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.observatory-devices__visitors {
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--observatory-fg);
  padding-left: 1rem;
}

.observatory-devices__error,
.observatory-devices__empty {
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
div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryDevicesWidget'] .widget-heading {
  display: none;
}
</style>

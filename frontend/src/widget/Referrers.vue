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

const { data, loading, error } = useWidgetData<MetricsResponse>('observatory/dashboard/get-metrics?type=referrer');

const displayedReferrers = computed(() => {
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
  return displayedReferrers.value.map(item => ({
    ...item,
    isSkeleton: false
  }));
});

const getFaviconUrl = (domain: string) => {
  return `https://icons.duckduckgo.com/ip3/${encodeURIComponent(domain)}.ico`;
};

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
    <div class="observatory-referrers__head">
      <div class="observatory-referrers__col">
        <div class="observatory-referrers__header">top referrers</div>
        <div class="observatory-referrers__subheader">last 7 days</div>
      </div>
    </div>

    <hr class="observatory-widget__divider" />

    <div class="observatory-referrers__list-container">
      <div v-if="error" class="observatory-referrers__error">
        Failed to load referrers
      </div>
      <div v-else-if="!loading && listItems.length === 0" class="observatory-referrers__empty">
        No referrers found
      </div>
      <div v-else class="observatory-referrers__list">
        <div
          v-for="(item, index) in listItems"
          :key="item.x"
          class="observatory-referrers__item"
          :class="{ 'observatory-referrers__item--real': !item.isSkeleton }"
          :style="!item.isSkeleton ? { animationDelay: getSkeletonDelay(index) } : undefined"
        >
          <!-- Background bar -->
          <div
            class="observatory-referrers__bar-bg"
            :class="{ 'observatory-referrers__bar-bg--skeleton': item.isSkeleton }"
            :style="{
              width: item.isSkeleton ? '100%' : `${(item.y / maxVisitors) * 100}%`,
              animationDelay: item.isSkeleton ? getSkeletonDelay(index) : undefined
            }"
          ></div>

          <div class="observatory-referrers__item-content">
            <div class="observatory-referrers__domain-group">
              <CrossFade>
                <div
                  v-if="item.isSkeleton"
                  key="skel-icon"
                  class="observatory-referrers__skeleton-icon"
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                ></div>
                <img
                  v-else
                  key="real-icon"
                  :src="getFaviconUrl(item.x)"
                  class="observatory-referrers__favicon"
                  alt=""
                  loading="lazy"
                  @error="($event.target as HTMLImageElement).style.display='none'"
                />
              </CrossFade>

              <CrossFade>
                <SkeletonText
                  v-if="item.isSkeleton"
                  key="skel-text"
                  style="width: 140px;"
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                />
                <span v-else key="real-text" class="observatory-referrers__domain">{{ item.x }}</span>
              </CrossFade>
            </div>

            <CrossFade>
              <SkeletonText
                v-if="item.isSkeleton"
                key="skel-val"
                style="width: 30px;"
                :style="{ animationDelay: getSkeletonDelay(index) }"
              />
              <span v-else key="real-val" class="observatory-referrers__visitors">{{ item.y }}</span>
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

.observatory-referrers__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.observatory-referrers__col {
  display: flex;
  flex-direction: column;
}

.observatory-referrers__header {
  font-size: 1.25rem;
  font-weight: 500;
  color: var(--observatory-fg);
  line-height: 1;
}

.observatory-referrers__subheader {
  font-size: 0.875rem;
  color: var(--observatory-fg);
  opacity: 0.7;
  margin-top: 0.25rem;
}

.observatory-referrers__list-container {
  position: relative;
  min-height: 200px;
  display: flex;
  flex-direction: column;
}

.observatory-referrers__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.observatory-referrers__item {
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
.observatory-referrers__item--real {
  animation: observatory-referrers-item-fade-in 0.5s ease backwards;
}

@keyframes observatory-referrers-item-fade-in {
  from {
    opacity: 0;
  }
}

.observatory-referrers__bar-bg {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background-color: color-mix(in srgb, var(--observatory-fg) 10%, transparent);
  border-radius: 4px;
  z-index: 0;
  transition: width 0.5s ease-out;
}

.observatory-referrers__bar-bg--skeleton {
  opacity: 0.4;
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-referrers__item-content {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0 0.5rem;
}

.observatory-referrers__domain-group {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  overflow: hidden;
}

.observatory-referrers__favicon {
  width: 16px;
  height: 16px;
  object-fit: contain;
  flex-shrink: 0;
}

.observatory-referrers__skeleton-icon {
  width: 16px;
  height: 16px;
  border-radius: 2px;
  background-color: color-mix(in srgb, var(--observatory-fg) 20%, transparent);
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-referrers__domain {
  font-size: 0.95rem;
  color: var(--observatory-fg);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.observatory-referrers__visitors {
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--observatory-fg);
  padding-left: 1rem;
}

.observatory-referrers__error,
.observatory-referrers__empty {
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
div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryReferrersWidget'] .widget-heading {
  display: none;
}

div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryReferrersWidget'] .pane {
  --pane-padding: 16px;
}
</style>

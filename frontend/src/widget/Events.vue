<script setup lang="ts">
import { computed, onUnmounted, watch } from 'vue';
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
  _syncing?: boolean;
}

const hasError = (s: AnalyticsStatus | undefined): boolean =>
  !!s && (!s.configured || !s.apiKeyValid);

const { data, loading, error, refetch } = useWidgetData<MetricsResponse>(
  'observatory/dashboard/get-top-events',
);

// While the historical events window is still syncing, nudge Craft's queue runner
// and poll for fresh data every 5s. The widget keeps rendering whatever data it has
// in the meantime (today's events come live from the API) and the closed-day totals
// fill in as sync completes.
let pollTimer: ReturnType<typeof setInterval> | null = null;
watch(
  () => data.value?._syncing,
  (syncing) => {
    if (syncing) {
      fetch(window.Craft.getActionUrl('queue/run'), { credentials: 'include' }).catch(() => {});
      if (!pollTimer) {
        pollTimer = setInterval(refetch, 5000);
      }
    } else if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  },
);
onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer);
});

const displayedEvents = computed(() => {
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
  return displayedEvents.value.map(item => ({
    ...item,
    isSkeleton: false
  }));
});

const maxEvents = computed(() => {
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
    <div class="observatory-events__head">
      <div class="observatory-events__col">
        <div class="observatory-events__header">top events</div>
        <div class="observatory-events__subheader">last 7 days</div>
      </div>
    </div>

    <hr class="observatory-widget__divider" />

    <div class="observatory-events__list-container">
      <div v-if="error" class="observatory-events__error">
        Failed to load events
      </div>
      <div v-else-if="!loading && listItems.length === 0" class="observatory-events__empty">
        No events tracked in the last 7 days
      </div>
      <div v-else class="observatory-events__list">
        <div
          v-for="(item, index) in listItems"
          :key="item.x"
          class="observatory-events__item"
          :class="{ 'observatory-events__item--real': !item.isSkeleton }"
          :style="!item.isSkeleton ? { animationDelay: getSkeletonDelay(index) } : undefined"
        >
          <!-- Background bar -->
          <div
            class="observatory-events__bar-bg"
            :class="{ 'observatory-events__bar-bg--skeleton': item.isSkeleton }"
            :style="{
              width: item.isSkeleton ? '100%' : `${maxEvents > 0 ? (item.y / maxEvents) * 100 : 0}%`,
              animationDelay: item.isSkeleton ? getSkeletonDelay(index) : undefined
            }"
          ></div>
          
          <div class="observatory-events__item-content">
            <div class="observatory-events__domain-group">
              <CrossFade>
                <SkeletonText 
                  v-if="item.isSkeleton" 
                  key="skel-text" 
                  style="width: 100px;" 
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                />
                <span v-else key="real-text" class="observatory-events__domain">{{ item.x }}</span>
              </CrossFade>
            </div>
            
            <CrossFade>
              <SkeletonText 
                v-if="item.isSkeleton" 
                key="skel-val" 
                style="width: 30px;" 
                :style="{ animationDelay: getSkeletonDelay(index) }"
              />
              <span v-else key="real-val" class="observatory-events__visitors">{{ item.y }}</span>
            </CrossFade>
          </div>
        </div>
      </div>
    </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.observatory-events__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.observatory-events__col {
  display: flex;
  flex-direction: column;
}

.observatory-events__header {
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--observatory-fg);
  line-height: 1;
}

.observatory-events__subheader {
  font-size: 0.875rem;
  color: var(--observatory-fg);
  opacity: 0.7;
  margin-top: 0.25rem;
}

.observatory-events__list-container {
  position: relative;
  min-height: 200px;
  display: flex;
  flex-direction: column;
}

.observatory-events__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.observatory-events__item {
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
.observatory-events__item--real {
  animation: observatory-events-item-fade-in 0.5s ease backwards;
}

@keyframes observatory-events-item-fade-in {
  from {
    opacity: 0;
  }
}

.observatory-events__bar-bg {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background-color: color-mix(in srgb, var(--observatory-fg) 10%, transparent);
  border-radius: 4px;
  z-index: 0;
  transition: width 0.5s ease-out;
}

.observatory-events__bar-bg--skeleton {
  opacity: 0.4;
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-events__item-content {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0 0.5rem;
}

.observatory-events__domain-group {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  overflow: hidden;
}

.observatory-events__domain {
  font-size: 0.95rem;
  color: var(--observatory-fg);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.observatory-events__visitors {
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--observatory-fg);
  padding-left: 1rem;
}

.observatory-events__error,
.observatory-events__empty {
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
div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryEventsWidget'] .widget-heading {
  display: none;
}

div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryEventsWidget'] .pane {
  --pane-padding: 16px;
}
</style>
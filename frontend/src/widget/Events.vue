<script setup lang="ts">
import { computed } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import SkeletonText from '@/shared/SkeletonText.vue';
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

const hasError = (s: UmamiStatus | undefined): boolean =>
  !!s && (!s.configured || !s.apiKeyValid);

const { data, loading, error } = useWidgetData<MetricsResponse>('umami-is/dashboard/get-top-events');

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
    <div class="umami-events__head">
      <div class="umami-events__col">
        <div class="umami-events__header">top events</div>
        <div class="umami-events__subheader">last 7 days</div>
      </div>
    </div>

    <hr class="umami-widget__divider" />

    <div class="umami-events__list-container">
      <div v-if="error" class="umami-events__error">
        Failed to load events
      </div>
      <div v-else-if="!loading && listItems.length === 0" class="umami-events__empty">
        No events tracked in the last 7 days
      </div>
      <div v-else class="umami-events__list">
        <div 
          v-for="(item, index) in listItems" 
          :key="item.x" 
          class="umami-events__item"
        >
          <!-- Background bar -->
          <div
            class="umami-events__bar-bg"
            :class="{ 'umami-events__bar-bg--skeleton': item.isSkeleton }"
            :style="{
              width: item.isSkeleton ? '100%' : `${maxEvents > 0 ? (item.y / maxEvents) * 100 : 0}%`,
              animationDelay: item.isSkeleton ? getSkeletonDelay(index) : undefined
            }"
          ></div>
          
          <div class="umami-events__item-content">
            <div class="umami-events__domain-group">
              <CrossFade>
                <SkeletonText 
                  v-if="item.isSkeleton" 
                  key="skel-text" 
                  style="width: 100px;" 
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                />
                <span v-else key="real-text" class="umami-events__domain">{{ item.x }}</span>
              </CrossFade>
            </div>
            
            <CrossFade>
              <SkeletonText 
                v-if="item.isSkeleton" 
                key="skel-val" 
                style="width: 30px;" 
                :style="{ animationDelay: getSkeletonDelay(index) }"
              />
              <span v-else key="real-val" class="umami-events__visitors">{{ item.y }}</span>
            </CrossFade>
          </div>
        </div>
      </div>
    </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.umami-events__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.umami-events__col {
  display: flex;
  flex-direction: column;
}

.umami-events__header {
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--umami-fg);
  line-height: 1;
}

.umami-events__subheader {
  font-size: 0.875rem;
  color: var(--umami-fg);
  opacity: 0.7;
  margin-top: 0.25rem;
}

.umami-events__list-container {
  position: relative;
  min-height: 200px;
  display: flex;
  flex-direction: column;
}

.umami-events__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.umami-events__item {
  position: relative;
  display: flex;
  align-items: center;
  min-height: 40px;
  padding: 0.5rem 0.25rem;
  border-radius: 4px;
}

.umami-events__bar-bg {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background-color: color-mix(in srgb, var(--umami-fg) 10%, transparent);
  border-radius: 4px;
  z-index: 0;
  transition: width 0.5s ease-out;
}

.umami-events__bar-bg--skeleton {
  opacity: 0.4;
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-events__item-content {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0 0.5rem;
}

.umami-events__domain-group {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  overflow: hidden;
}

.umami-events__domain {
  font-size: 0.95rem;
  color: var(--umami-fg);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.umami-events__visitors {
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--umami-fg);
  padding-left: 1rem;
}

.umami-events__error,
.umami-events__empty {
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
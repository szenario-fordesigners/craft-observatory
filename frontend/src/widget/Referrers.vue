<script setup lang="ts">
import { computed } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import SkeletonText from '@/shared/SkeletonText.vue';
import { useWidgetData } from '@/shared/useWidgetData';

interface MetricEntry {
  x: string;
  y: number;
}

const { data, loading, error } = useWidgetData<MetricEntry[]>('umami-is/dashboard/get-metrics?type=referrer');

// Limit the number of referrers to display so it doesn't get too long
const displayedReferrers = computed(() => {
  if (!data.value) return [];
  // Sort by visitors descending just in case, and take top 10
  return [...data.value].sort((a, b) => b.y - a.y).slice(0, 10);
});

// Provide a unified list of either real items or fake items for skeleton loading
const listItems = computed(() => {
  if (loading.value && (!data.value || data.value.length === 0)) {
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
  return `https://icons.duckduckgo.com/ip3/${domain}.ico`;
};

// Calculate max visitors to scale background bars
const maxVisitors = computed(() => {
  if (!data.value || data.value.length === 0) return 0;
  return Math.max(0, ...data.value.map((d) => d.y));
});

// Stagger delays for a nice cascade effect
const getSkeletonDelay = (index: number) => `${index * 100}ms`;
</script>

<template>
  <WidgetFrame>
    <div class="umami-referrers__head">
      <div class="umami-referrers__col">
        <div class="umami-referrers__header">top referrers</div>
        <div class="umami-referrers__subheader">last 7 days</div>
      </div>
    </div>

    <hr class="umami-widget__divider" />

    <div class="umami-referrers__list-container">
      <div v-if="error" class="umami-referrers__error">
        Failed to load referrers
      </div>
      <div v-else-if="!loading && listItems.length === 0" class="umami-referrers__empty">
        No referrers found
      </div>
      <div v-else class="umami-referrers__list">
        <div 
          v-for="(item, index) in listItems" 
          :key="item.x" 
          class="umami-referrers__item"
        >
          <!-- Background bar -->
          <div 
            class="umami-referrers__bar-bg"
            :class="{ 'umami-referrers__bar-bg--skeleton': item.isSkeleton }"
            :style="{ 
              width: item.isSkeleton ? '100%' : `${(item.y / maxVisitors) * 100}%`,
              animationDelay: item.isSkeleton ? getSkeletonDelay(index) : undefined 
            }"
          ></div>
          
          <div class="umami-referrers__item-content">
            <div class="umami-referrers__domain-group">
              <CrossFade>
                <div 
                  v-if="item.isSkeleton" 
                  key="skel-icon" 
                  class="umami-referrers__skeleton-icon"
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                ></div>
                <img 
                  v-else 
                  key="real-icon"
                  :src="getFaviconUrl(item.x)" 
                  class="umami-referrers__favicon" 
                  alt="" 
                  loading="lazy" 
                  @error="$event.target.style.display='none'"
                />
              </CrossFade>

              <CrossFade>
                <SkeletonText 
                  v-if="item.isSkeleton" 
                  key="skel-text" 
                  style="width: 140px;" 
                  :style="{ animationDelay: getSkeletonDelay(index) }"
                />
                <span v-else key="real-text" class="umami-referrers__domain">{{ item.x }}</span>
              </CrossFade>
            </div>
            
            <CrossFade>
              <SkeletonText 
                v-if="item.isSkeleton" 
                key="skel-val" 
                style="width: 30px;" 
                :style="{ animationDelay: getSkeletonDelay(index) }"
              />
              <span v-else key="real-val" class="umami-referrers__visitors">{{ item.y }}</span>
            </CrossFade>
          </div>
        </div>
      </div>
    </div>
  </WidgetFrame>
</template>

<style scoped>
.umami-referrers__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
}

.umami-referrers__col {
  display: flex;
  flex-direction: column;
}

.umami-referrers__header {
  font-size: 1.125rem;
  font-weight: 500;
  color: var(--umami-fg);
  line-height: 1;
}

.umami-referrers__subheader {
  font-size: 0.875rem;
  color: var(--umami-fg);
  opacity: 0.7;
  margin-top: 0.25rem;
}

.umami-referrers__list-container {
  position: relative;
  min-height: 200px;
  display: flex;
  flex-direction: column;
}

.umami-referrers__list {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  width: 100%;
}

.umami-referrers__item {
  position: relative;
  display: flex;
  align-items: center;
  min-height: 40px;
  padding: 0.5rem 0.25rem;
  border-radius: 4px;
}

.umami-referrers__bar-bg {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  background-color: color-mix(in srgb, var(--umami-fg) 10%, transparent);
  border-radius: 4px;
  z-index: 0;
  transition: width 0.5s ease-out;
}

.umami-referrers__bar-bg--skeleton {
  opacity: 0.4;
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-referrers__item-content {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding: 0 0.5rem;
}

.umami-referrers__domain-group {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  overflow: hidden;
}

.umami-referrers__favicon {
  width: 16px;
  height: 16px;
  object-fit: contain;
  flex-shrink: 0;
}

.umami-referrers__skeleton-icon {
  width: 16px;
  height: 16px;
  border-radius: 2px;
  background-color: color-mix(in srgb, var(--umami-fg) 20%, transparent);
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-referrers__domain {
  font-size: 0.95rem;
  color: var(--umami-fg);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.umami-referrers__visitors {
  font-size: 0.95rem;
  font-weight: 500;
  color: var(--umami-fg);
  padding-left: 1rem;
}

.umami-referrers__error,
.umami-referrers__empty {
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

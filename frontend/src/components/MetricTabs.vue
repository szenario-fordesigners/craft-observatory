<script setup lang="ts">
import MetricList from '@/components/MetricList.vue';
import CrossFade from '@/shared/CrossFade.vue';
import type { AnalyticsMetric } from '@/shared/analyticsTypes';
import { ref } from 'vue';

const props = defineProps<{
  tabs: { key: string; label: string }[];
  metrics: Record<string, AnalyticsMetric[]>;
  loading?: boolean;
}>();

const active = ref(props.tabs[0].key);
</script>

<template>
  <!-- Tab switches are a click away from a fade, so they run faster than the
       0.4s default used for data arriving on its own. -->
  <div style="--observatory-fade-duration: 0.15s">
    <div class="mb-2 flex space-x-6 border-b border-observatory-fg/45 pb-2">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        @click="active = tab.key"
        :class="[
          'flex-1 text-center text-sm font-medium transition-colors duration-200',
          active === tab.key
            ? '-mb-2.5 border-b-2 border-observatory-fg text-observatory-fg'
            : 'text-observatory-fg/60 hover:text-observatory-fg',
        ]"
      >
        {{ tab.label }}
      </button>
    </div>
    <CrossFade mode="out-in">
      <MetricList :key="active" :data="metrics[active] ?? []" :loading="loading" />
    </CrossFade>
  </div>
</template>

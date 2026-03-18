<script setup lang="ts">
import { computed } from 'vue';
import type { PropType } from 'vue';
import type { WebsiteMetric } from '@umami/api-client';

const props = defineProps({
  data: {
    type: Array as PropType<WebsiteMetric[]>,
    required: true
  },
  loading: {
    type: Boolean,
    default: false
  },
  label: {
    type: String,
    required: false,
    default: ''
  }
});

const maxCount = computed(() => {
  return Math.max(...props.data.map((d: WebsiteMetric) => d.y), 0);
});

const getPercentage = (val: number) => {
  if (maxCount.value === 0) return 0;
  return (val / maxCount.value) * 100;
};
</script>

<template>
  <div class="mt-4">
    <h3 v-if="label" class="text-sm font-semibold text-gray-500 mb-2 uppercase">{{ label }}</h3>
    
    <div v-if="loading" class="animate-pulse space-y-2">
      <div class="h-6 bg-gray-200 rounded w-full"></div>
      <div class="h-6 bg-gray-200 rounded w-5/6"></div>
      <div class="h-6 bg-gray-200 rounded w-4/6"></div>
    </div>
    
    <div v-else-if="data.length === 0" class="text-gray-500 text-sm py-4 italic">
      No data available
    </div>
    
    <div v-else class="space-y-1 max-h-64 overflow-y-auto pr-2">
      <div 
        v-for="item in data" 
        :key="item.x" 
        class="relative flex justify-between items-center text-sm py-1.5"
      >
        <!-- Background Bar -->
        <div 
          class="absolute top-0 bottom-0 left-0 bg-blue-50 rounded-sm z-0" 
          :style="{ width: `${getPercentage(item.y)}%` }"
        ></div>
        
        <!-- Label and Value -->
        <div class="relative z-10 truncate pl-2 font-medium text-gray-800">
          {{ item.x }}
        </div>
        <div class="relative z-10 pr-2 text-gray-600">
          {{ item.y }}
        </div>
      </div>
    </div>
  </div>
</template>

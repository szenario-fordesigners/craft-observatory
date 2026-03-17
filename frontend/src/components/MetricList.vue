<script setup lang="ts">
import { ref, watch } from 'vue';
import type { WebsiteMetric } from '@umami/api-client';

const props = defineProps({
  type: {
    type: String,
    required: true
  },
  startAt: {
    type: Number,
    required: true
  },
  endAt: {
    type: Number,
    required: true
  },
  label: {
    type: String,
    required: false,
    default: ''
  }
});

const data = ref<WebsiteMetric[]>([]);
const maxCount = ref(0);
const loading = ref(false);

const fetchData = async () => {
  loading.value = true;
  try {
    const url = new URL(window.Craft.getActionUrl('umami-is/dashboard/get-metrics'), window.location.origin);
    url.searchParams.append('type', props.type);
    url.searchParams.append('startAt', props.startAt.toString());
    url.searchParams.append('endAt', props.endAt.toString());

    const response = await fetch(url.toString(), {
      headers: {
        'Accept': 'application/json'
      }
    });
    
    if (response.ok) {
      const result = await response.json();
      data.value = Array.isArray(result) ? result : [];
      maxCount.value = Math.max(...data.value.map((d: WebsiteMetric) => d.y), 0);
    }
  } catch (e) {
    console.error(`Failed to fetch metrics for ${props.type}`, e);
  } finally {
    loading.value = false;
  }
};

watch(
  () => [props.startAt, props.endAt, props.type],
  () => {
    fetchData();
  },
  { immediate: true }
);

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

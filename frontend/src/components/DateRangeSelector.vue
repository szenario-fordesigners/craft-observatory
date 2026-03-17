<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { useDateRange, type RangeValue } from '../composables/useDateRange';

defineProps({
  modelValue: {
    type: String as () => RangeValue,
    required: true
  }
});

const emit = defineEmits(['update:modelValue']);

const { availableRanges } = useDateRange();
const isOpen = ref(false);

const toggleDropdown = () => {
  isOpen.value = !isOpen.value;
};

const selectRange = (value: RangeValue) => {
  emit('update:modelValue', value);
  isOpen.value = false;
};

const getLabel = (val: string) => {
  return availableRanges.value.find(r => r.value === val)?.label || 'Select range';
};

// Close dropdown on outside click
const dropdownRef = ref<HTMLElement | null>(null);

const handleClickOutside = (e: MouseEvent) => {
  if (dropdownRef.value && !dropdownRef.value.contains(e.target as Node)) {
    isOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>

<template>
  <div class="relative inline-block text-left" ref="dropdownRef">
    <div>
      <button 
        type="button" 
        @click="toggleDropdown"
        class="inline-flex justify-center w-full rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none"
      >
        {{ getLabel(modelValue) }}
        <!-- Heroicon name: solid/chevron-down -->
        <svg class="-mr-1 ml-2 h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
          <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
        </svg>
      </button>
    </div>

    <transition
      enter-active-class="transition ease-out duration-100"
      enter-from-class="transform opacity-0 scale-95"
      enter-to-class="transform opacity-100 scale-100"
      leave-active-class="transition ease-in duration-75"
      leave-from-class="transform opacity-100 scale-100"
      leave-to-class="transform opacity-0 scale-95"
    >
      <div 
        v-if="isOpen" 
        class="origin-top-right absolute right-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 divide-y divide-gray-100 focus:outline-none z-50 text-black max-h-96 overflow-y-auto"
      >
        <div class="py-1">
          <button
            v-for="range in availableRanges"
            :key="range.value"
            @click="selectRange(range.value)"
            class="group flex items-center justify-between w-full px-4 py-2 text-sm text-left hover:bg-gray-100"
            :class="[modelValue === range.value ? 'bg-gray-50 font-bold' : 'text-gray-700']"
          >
            {{ range.label }}
            <svg v-if="modelValue === range.value" class="h-4 w-4 text-black" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

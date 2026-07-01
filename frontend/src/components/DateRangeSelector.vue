<script setup lang="ts">
import { computed, ref, onMounted, onUnmounted, watch } from 'vue';
import { useDateRange, type CustomDateRange, type RangeValue } from '../composables/useDateRange';

const props = defineProps({
  modelValue: {
    type: String as () => RangeValue,
    required: true,
  },
  customRange: {
    type: Object as () => CustomDateRange,
    required: true,
  },
});

const emit = defineEmits<{
  'update:modelValue': [value: RangeValue];
  'update:customRange': [value: CustomDateRange];
}>();

const { availableRanges } = useDateRange();
const presetRanges = computed(() =>
  availableRanges.value.filter((range) => range.value !== 'custom'),
);
const isOpen = ref(false);
const customStartDate = ref(props.customRange.startDate);
const customEndDate = ref(props.customRange.endDate);

const toggleDropdown = () => {
  isOpen.value = !isOpen.value;
};

const selectRange = (value: RangeValue) => {
  emit('update:modelValue', value);
  isOpen.value = false;
};

const applyCustomRange = () => {
  if (!customStartDate.value || !customEndDate.value) return;

  const startDate =
    customStartDate.value <= customEndDate.value ? customStartDate.value : customEndDate.value;
  const endDate =
    customStartDate.value <= customEndDate.value ? customEndDate.value : customStartDate.value;

  emit('update:customRange', { startDate, endDate });
  emit('update:modelValue', 'custom');
  isOpen.value = false;
};

const getLabel = (val: string) => {
  if (val === 'custom') {
    return `${props.customRange.startDate} - ${props.customRange.endDate}`;
  }

  return availableRanges.value.find((r) => r.value === val)?.label || 'Select range';
};

watch(
  () => props.customRange,
  (range) => {
    customStartDate.value = range.startDate;
    customEndDate.value = range.endDate;
  },
);

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
        class="inline-flex w-full justify-center rounded-[0.7rem] border border-observatory-fg/30 bg-observatory-bg px-4 py-2 text-sm font-medium text-observatory-fg hover:bg-observatory-fg/[0.08] focus:outline-none"
      >
        {{ getLabel(modelValue) }}
        <!-- Heroicon name: solid/chevron-down -->
        <svg
          class="-mr-1 ml-2 h-5 w-5"
          xmlns="http://www.w3.org/2000/svg"
          viewBox="0 0 20 20"
          fill="currentColor"
          aria-hidden="true"
        >
          <path
            fill-rule="evenodd"
            d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
            clip-rule="evenodd"
          />
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
        class="absolute right-0 z-50 mt-2 max-h-110 w-56 origin-top-right divide-y divide-observatory-fg/15 overflow-y-auto rounded-[0.7rem] bg-observatory-bg text-observatory-fg shadow-lg ring-1 ring-observatory-fg/20 focus:outline-none"
      >
        <div class="py-1">
          <button
            v-for="range in presetRanges"
            :key="range.value"
            @click="selectRange(range.value)"
            class="group flex w-full items-center justify-between px-4 py-2 text-left text-sm hover:bg-observatory-fg/10"
            :class="[
              modelValue === range.value
                ? 'bg-observatory-fg/[0.08] font-medium'
                : 'text-observatory-fg',
            ]"
          >
            {{ range.label }}
            <svg
              v-if="modelValue === range.value"
              class="h-4 w-4 text-observatory-fg"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M5 13l4 4L19 7"
              />
            </svg>
          </button>
        </div>
        <div class="space-y-3 px-4 py-3">
          <div class="text-sm font-medium text-observatory-fg">Custom range</div>
          <label class="block text-xs font-medium text-observatory-fg/70">
            From
            <input
              v-model="customStartDate"
              type="date"
              class="mt-1 block w-full rounded border border-observatory-fg/30 bg-observatory-bg px-2 py-1 text-sm text-observatory-fg"
            />
          </label>
          <label class="block text-xs font-medium text-observatory-fg/70">
            To
            <input
              v-model="customEndDate"
              type="date"
              class="mt-1 block w-full rounded border border-observatory-fg/30 bg-observatory-bg px-2 py-1 text-sm text-observatory-fg"
            />
          </label>
          <button
            type="button"
            class="w-full rounded bg-observatory-fg px-3 py-2 text-sm font-medium text-observatory-bg hover:bg-observatory-fg/90"
            @click="applyCustomRange"
          >
            Apply custom range
          </button>
        </div>
      </div>
    </transition>
  </div>
</template>

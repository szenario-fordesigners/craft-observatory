<script setup lang="ts">
import { computed } from 'vue';
import Tooltip from '@/shared/Tooltip.vue';

interface HeatmapCell {
  weekday: number; // 0=Mon … 6=Sun
  hour: number;    // 0–23
  visitors: number;
}

const props = defineProps<{
  cells: HeatmapCell[];
  maxVisitors: number;
  daysWithData: number;
  loading?: boolean;
  locale?: string;
}>();

const DAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const HOURS = Array.from({ length: 24 }, (_, i) => i);

const cellMap = computed(() => {
  const map = new Map<string, number>();
  for (const cell of props.cells) {
    map.set(`${cell.weekday}:${cell.hour}`, cell.visitors);
  }
  return map;
});

const visitors = (weekday: number, hour: number) =>
  cellMap.value.get(`${weekday}:${hour}`) ?? 0;

// Ramp the cell fill from a faint observatory-fg tint (no data) to a strong one (peak),
// mirroring the widget heatmap's opacity ramp (0.08 empty → ~0.85 peak).
const cellColor = (v: number): string => {
  if (props.maxVisitors === 0 || v === 0) {
    return 'color-mix(in srgb, var(--observatory-fg) 8%, transparent)';
  }
  const t = Math.min(v / props.maxVisitors, 1);
  const pct = Math.round((0.15 + t * 0.7) * 100);
  return `color-mix(in srgb, var(--observatory-fg) ${pct}%, transparent)`;
};

const hourFmt = computed(() => new Intl.DateTimeFormat(props.locale ?? 'en', { hour: 'numeric' }));

const formatHour = (h: number): string => hourFmt.value.format(new Date(2000, 0, 1, h));

const tooltip = (weekday: number, hour: number): string => {
  const v = visitors(weekday, hour);
  const label = `${DAY_LABELS[weekday]} ${formatHour(hour)}`;
  return v > 0 ? `${label}: avg ${v} visitor${v !== 1 ? 's' : ''}` : `${label}: no data`;
};

// Top 3 (weekday, hour) cells ranked by average visitors.
const peakTimes = computed(() => {
  const entries: { weekday: number; hour: number; value: number }[] = [];
  for (const cell of props.cells) {
    if (cell.visitors > 0) {
      entries.push({ weekday: cell.weekday, hour: cell.hour, value: cell.visitors });
    }
  }
  return entries.sort((a, b) => b.value - a.value).slice(0, 3);
});

const peakLabel = (weekday: number, hour: number): string =>
  `${DAY_LABELS[weekday]} ${formatHour(hour)}`;

const peakRankMap = computed(() => {
  const map = new Map<string, number>();
  peakTimes.value.forEach((peak, index) => {
    map.set(`${peak.weekday}:${peak.hour}`, index + 1);
  });
  return map;
});

const peakRank = (weekday: number, hour: number): number =>
  peakRankMap.value.get(`${weekday}:${hour}`) ?? 0;
</script>

<template>
  <div class="observatory-cp-heatmap">
    <!-- Skeleton while loading -->
    <div v-if="loading" class="h-40 animate-pulse rounded bg-observatory-fg/10" />

    <div v-else>
      <!-- No data state -->
      <div
        v-if="cells.length === 0"
        class="flex h-32 items-center justify-center rounded border border-dashed border-observatory-fg/20 text-sm text-observatory-fg/50"
      >
        No hourly data yet — syncing in the background.
      </div>

      <template v-else>
        <!-- Hour axis labels (top) -->
        <div class="mb-1 flex items-center">
          <!-- spacer for day-label column -->
          <div class="w-9 shrink-0" />
          <div class="grid flex-1" :style="{ gridTemplateColumns: `repeat(24, minmax(0, 1fr))` }">
            <div
              v-for="h in HOURS"
              :key="h"
              class="text-center text-[9px] leading-none text-observatory-fg/55"
            >
              {{ h % 3 === 0 ? formatHour(h) : '' }}
            </div>
          </div>
        </div>

        <!-- Rows: one per weekday -->
        <div v-for="(day, di) in DAY_LABELS" :key="day" class="mb-0.5 flex items-center gap-1">
          <!-- Day label -->
          <div class="w-8 shrink-0 text-right text-[10px] leading-none text-observatory-fg/65">{{ day }}</div>

          <!-- 24 hour cells -->
          <div class="grid flex-1 gap-px" :style="{ gridTemplateColumns: `repeat(24, minmax(0, 1fr))` }">
            <Tooltip v-for="h in HOURS" :key="h" :text="tooltip(di, h)">
              <div
                class="relative grid aspect-square w-full cursor-default place-items-center rounded-sm transition-opacity hover:opacity-80"
                :class="{ 'ring-1 ring-inset ring-observatory-bg/70': peakRank(di, h) }"
                :style="{ backgroundColor: cellColor(visitors(di, h)) }"
              >
                <span
                  v-if="peakRank(di, h)"
                  class="flex h-3 w-3 items-center justify-center rounded-full bg-observatory-bg/90 text-[8px] font-bold leading-none tabular-nums text-observatory-fg"
                >
                  {{ peakRank(di, h) }}
                </span>
              </div>
            </Tooltip>
          </div>
        </div>

        <!-- Legend + metadata -->
        <div class="mt-3 flex items-center justify-between text-[10px] text-observatory-fg/55">
          <span>Based on {{ daysWithData }} day{{ daysWithData !== 1 ? 's' : '' }} of data</span>
          <div class="flex items-center gap-1">
            <span>Less</span>
            <div
              v-for="t in [0, 0.25, 0.5, 0.75, 1]"
              :key="t"
              class="h-3 w-3 rounded-sm"
              :style="{ backgroundColor: cellColor(t * maxVisitors) }"
            />
            <span>More</span>
          </div>
        </div>

        <!-- Peak times -->
        <div class="mt-3 flex flex-wrap items-center gap-2 border-t border-observatory-fg/15 pt-3">
          <span class="text-[10px] font-semibold uppercase tracking-wide text-observatory-fg/60">
            Peak times
          </span>
          <template v-if="peakTimes.length">
            <span
              v-for="(peak, i) in peakTimes"
              :key="i"
              class="inline-flex items-center gap-1.5 rounded-full bg-observatory-fg/10 py-1 pl-1 pr-2.5 text-xs text-observatory-fg"
            >
              <span
                class="flex h-4 w-4 items-center justify-center rounded-full bg-observatory-fg text-[10px] font-bold tabular-nums text-observatory-bg"
              >
                {{ i + 1 }}
              </span>
              {{ peakLabel(peak.weekday, peak.hour) }}
            </span>
          </template>
          <span v-else class="text-xs text-observatory-fg/55">no data yet</span>
        </div>
      </template>
    </div>
  </div>
</template>


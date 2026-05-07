<script setup lang="ts">
import { computed } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import SkeletonText from '@/shared/SkeletonText.vue';
import StatusNotice, { type UmamiStatus } from '@/shared/StatusNotice.vue';
import Tooltip from '@/shared/Tooltip.vue';
import { useWidgetData } from '@/shared/useWidgetData';

interface HeatmapCell {
  weekday: number; // 0=Mon … 6=Sun
  hour: number;
  visitors: number;
}

interface HeatmapData {
  cells: HeatmapCell[];
  maxVisitors: number;
  daysWithData: number;
  _status?: UmamiStatus;
}

const props = defineProps<{ locale?: string }>();

defineOptions({ name: 'UmamiHeatmap' });

const hasError = (s: UmamiStatus | undefined): boolean => !!s && (!s.configured || !s.apiKeyValid);

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

const BUCKETS = [
  { label: 'Night', hours: [0, 1, 2, 3] },
  { label: 'Early', hours: [4, 5, 6, 7] },
  { label: 'Morning', hours: [8, 9, 10, 11] },
  { label: 'Afternoon', hours: [12, 13, 14, 15] },
  { label: 'Evening', hours: [16, 17, 18, 19] },
  { label: 'Late', hours: [20, 21, 22, 23] },
];

const hourFmt = computed(() => new Intl.DateTimeFormat(props.locale ?? 'en', { hour: 'numeric' }));

const bucketTooltip = (di: number, bi: number): string => {
  const bucket = BUCKETS[bi];
  const start = new Date(2000, 0, 1, bucket.hours[0]);
  const end = new Date(2000, 0, 1, bucket.hours[bucket.hours.length - 1]);
  const range = `${hourFmt.value.format(start)} – ${hourFmt.value.format(end)}`;
  if (!data.value) return `${bucket.label} · ${range}`;
  const value = bucketGrid.value[di][bi];
  const visitors = value > 0 ? `· avg. ${Math.round(value)} visitors` : '· no data';
  return `${bucket.label} · ${range} ${visitors}`;
};

const { data } = useWidgetData<HeatmapData>('umami-is/dashboard/get-heatmap-data', { days: 56 });

const cellMap = computed(() => {
  const map = new Map<string, number>();
  for (const cell of data.value?.cells ?? []) {
    map.set(`${cell.weekday}:${cell.hour}`, cell.visitors);
  }
  return map;
});

// 7×6 grid of bucket-averaged visitor values (avg of non-zero hours within each bucket)
const bucketGrid = computed((): number[][] =>
  Array.from({ length: 7 }, (_, weekday) =>
    BUCKETS.map((bucket) => {
      const vals = bucket.hours.map((h) => cellMap.value.get(`${weekday}:${h}`) ?? 0);
      const nonZero = vals.filter((v) => v > 0);
      return nonZero.length > 0 ? nonZero.reduce((a, b) => a + b, 0) / nonZero.length : 0;
    }),
  ),
);

const maxBucketValue = computed(() => Math.max(0, ...bucketGrid.value.flat()));

// Maps a cell value to an opacity between 0.08 (no data) and 0.85 (peak).
const cellOpacity = (value: number): number => {
  if (maxBucketValue.value === 0 || value === 0) return 0.08;
  return 0.15 + (value / maxBucketValue.value) * 0.7;
};

// Top 3 (weekday, bucket) combinations sorted by average visitors.
const peakTimes = computed(() => {
  const entries: { weekday: number; bucketIdx: number; value: number }[] = [];
  bucketGrid.value.forEach((row, weekday) => {
    row.forEach((value, bucketIdx) => {
      if (value > 0) entries.push({ weekday, bucketIdx, value });
    });
  });
  return entries.sort((a, b) => b.value - a.value).slice(0, 3);
});

const peakRankMap = computed(() => {
  const map = new Map<string, number>();
  peakTimes.value.forEach((peak, index) => {
    map.set(`${peak.weekday}:${peak.bucketIdx}`, index + 1);
  });
  return map;
});

const peakRank = (weekday: number, bucketIdx: number): number =>
  peakRankMap.value.get(`${weekday}:${bucketIdx}`) ?? 0;

const peakLabel = (weekday: number, bucketIdx: number) =>
  `${DAY_NAMES[weekday]} ${BUCKETS[bucketIdx].label.toLowerCase()}`;
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
      <div class="umami-heatmap">
        <!-- Title -->
        <div>
          <div class="umami-heatmap__title">traffic patterns</div>
          <div class="umami-heatmap__subtitle">last 8 weeks</div>
        </div>

        <!-- 7×6 condensed heatmap -->
        <div class="umami-heatmap__grid-wrap">
          <!-- Column labels -->
          <div class="umami-heatmap__col-labels">
            <div />
            <div v-for="bucket in BUCKETS" :key="bucket.label" class="umami-heatmap__col-label">
              {{ bucket.label }}
            </div>
          </div>

          <!-- One row per weekday -->
          <div v-for="(day, di) in DAYS" :key="day" class="umami-heatmap__row">
            <div class="umami-heatmap__row-label">{{ day }}</div>
            <Tooltip v-for="(_, bi) in BUCKETS" :key="bi" :text="bucketTooltip(di, bi)">
              <div
                class="umami-heatmap__cell"
                :class="{
                  'umami-heatmap__cell--skeleton': !data,
                  'umami-heatmap__cell--peak': data && peakRank(di, bi),
                }"
                :style="data ? { opacity: cellOpacity(bucketGrid[di][bi]) } : {}"
              >
                <span v-if="data && peakRank(di, bi)" class="umami-heatmap__cell-rank">
                  {{ peakRank(di, bi) }}
                </span>
              </div>
            </Tooltip>
          </div>
        </div>

        <!-- Top 3 peak times -->
        <div class="umami-heatmap__peaks">
          <div class="umami-heatmap__peaks-title">peak times</div>
          <CrossFade>
            <div v-if="data && peakTimes.length" key="peaks-real" class="umami-heatmap__peaks-list">
              <div v-for="(peak, i) in peakTimes" :key="i" class="umami-heatmap__peak-item">
                <span class="umami-heatmap__peak-rank">{{ i + 1 }}</span>
                <span>{{ peakLabel(peak.weekday, peak.bucketIdx) }}</span>
              </div>
            </div>
            <div v-else-if="data" key="peaks-empty" class="umami-heatmap__peaks-empty">
              no data yet
            </div>
            <div v-else key="peaks-skel" class="umami-heatmap__peaks-list">
              <div v-for="i in 3" :key="i" class="umami-heatmap__peak-item">
                <div class="umami-heatmap__peak-rank umami-heatmap__peak-rank--skeleton" />
                <SkeletonText />
              </div>
            </div>
          </CrossFade>
        </div>
      </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.umami-heatmap {
  display: flex;
  flex-direction: column;
  gap: 1.1rem;
}

.umami-heatmap__title {
  font-size: 1.25rem;
  margin-bottom: 0.1rem;
}

.umami-heatmap__subtitle {
  font-size: 0.75rem;
  opacity: 0.6;
}

/* Grid */
.umami-heatmap__grid-wrap {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.umami-heatmap__col-labels,
.umami-heatmap__row {
  display: grid;
  grid-template-columns: 2.25rem repeat(6, minmax(0, 1fr));
  gap: 3px;
  align-items: center;
}

.umami-heatmap__col-label {
  text-align: center;
  font-size: 0.55rem;
  opacity: 0.55;
  line-height: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.umami-heatmap__row-label {
  font-size: 0.65rem;
  opacity: 0.65;
  text-align: right;
  padding-right: 5px;
  line-height: 1;
}

.umami-heatmap__cell {
  position: relative;
  display: grid;
  place-items: center;
  background-color: var(--umami-fg);
  border-radius: 3px;
  aspect-ratio: 2 / 1;
  transition: opacity 0.4s ease;
}

.umami-heatmap__cell--peak {
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--umami-bg) 70%, transparent);
}

.umami-heatmap__cell-rank {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background-color: color-mix(in srgb, var(--umami-bg) 86%, transparent);
  color: var(--umami-fg);
  font-size: 10px;
  font-weight: bold;
  font-variant-numeric: tabular-nums;
  line-height: 16px;
  text-align: center;
}

.umami-heatmap__cell--skeleton {
  opacity: 0.15 !important;
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

/* Stagger skeleton pulse row by row */
.umami-heatmap__row:nth-child(2) .umami-heatmap__cell--skeleton {
  animation-delay: 0ms;
}
.umami-heatmap__row:nth-child(3) .umami-heatmap__cell--skeleton {
  animation-delay: 80ms;
}
.umami-heatmap__row:nth-child(4) .umami-heatmap__cell--skeleton {
  animation-delay: 160ms;
}
.umami-heatmap__row:nth-child(5) .umami-heatmap__cell--skeleton {
  animation-delay: 240ms;
}
.umami-heatmap__row:nth-child(6) .umami-heatmap__cell--skeleton {
  animation-delay: 320ms;
}
.umami-heatmap__row:nth-child(7) .umami-heatmap__cell--skeleton {
  animation-delay: 400ms;
}
.umami-heatmap__row:nth-child(8) .umami-heatmap__cell--skeleton {
  animation-delay: 480ms;
}

/* Peak times */
.umami-heatmap__peaks {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  align-items: center;
  gap: 0.75rem;
  padding: 0.65rem 0.75rem;
  border-radius: 0.7rem;
  background-color: color-mix(in srgb, var(--umami-fg) 8%, transparent);
}

.umami-heatmap__peaks-title {
  font-size: 0.65rem;
  line-height: 1;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  opacity: 0.62;
  white-space: nowrap;
}

.umami-heatmap__peaks-list {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
  min-width: 0;
}

.umami-heatmap__peak-item {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  min-width: 0;
  padding: 0.25rem 0.45rem 0.25rem 0.25rem;
  border-radius: 999px;
  background-color: color-mix(in srgb, var(--umami-fg) 10%, transparent);
  font-size: 0.75rem;
  line-height: 1;
}

.umami-heatmap__peak-rank {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background-color: var(--umami-fg);
  color: var(--umami-bg);
  font-size: 10px;
  font-weight: bold;
  font-variant-numeric: tabular-nums;
  line-height: 16px;
  text-align: center;
  flex-shrink: 0;
  opacity: 0.75;
}

.umami-heatmap__peak-rank--skeleton {
  opacity: 0.2;
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-heatmap__peaks-empty {
  font-size: 0.75rem;
  opacity: 0.55;
}

@container umami-pane (max-width: 440px) {
  .umami-heatmap__peaks {
    grid-template-columns: 1fr;
    align-items: start;
  }
}

@media (prefers-reduced-motion: reduce) {
  .umami-heatmap__cell,
  .umami-heatmap__cell--skeleton,
  .umami-heatmap__peak-rank--skeleton {
    transition: none;
    animation: none;
    opacity: 0.15;
  }
}
</style>

<style>
div[data-type='szenario\\craftumamiis\\widgets\\UmamiIsHeatmapWidget'] .widget-heading {
  display: none;
}
</style>

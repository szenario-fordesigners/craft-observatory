<script setup lang="ts">
import { computed, onUnmounted, watch } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import SkeletonText from '@/shared/SkeletonText.vue';
import CrossFade from '@/shared/CrossFade.vue';
import StatusNotice from '@/shared/StatusNotice.vue';
import type { AnalyticsStatus } from '@/shared/analyticsTypes';
import Tooltip from '@/shared/Tooltip.vue';
import { useWidgetData } from '@/shared/useWidgetData';

interface DailyEntry {
  date: string;
  views: number;
  queued?: boolean;
}

interface Usage {
  totalViews: number;
  priorViews: number;
  deltaPercent: number;
  deltaDirection: number;
  avgDuration: number;
  daily: DailyEntry[];
  _status?: AnalyticsStatus;
  _syncing?: boolean;
}

const hasError = (s: AnalyticsStatus | undefined): boolean => !!s && (!s.configured || !s.apiKeyValid);

const props = defineProps<{
  locale?: string;
}>();

const skeletonHeights = [55, 35, 48, 70, 100, 28, 60];

const { data, refetch } = useWidgetData<Usage>('observatory/dashboard/get-usage-summary');

const ready = computed(() => (data.value && !data.value._syncing ? data.value : null));

let pollTimer: ReturnType<typeof setInterval> | null = null;

watch(
  () => data.value?._syncing,
  (syncing) => {
    if (syncing) {
      fetch(window.Craft.getActionUrl('queue/run'), { credentials: 'include' }).catch(() => { });
      if (!pollTimer) {
        pollTimer = setInterval(refetch, 5000);
      }
    } else {
      if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
      }
    }
  },
);

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer);
});

const localeId = props.locale || 'en';
const numberFormatter = new Intl.NumberFormat(localeId);
const dayFormatter = new Intl.DateTimeFormat(localeId, { weekday: 'short' });

const formatNumber = (n: number) => numberFormatter.format(n);

// Average visit duration: "45 s" below a minute, "2 m 37 s" above, "2 m" on the dot.
const formatDuration = (seconds: number): string => {
  if (seconds < 60) return `${seconds} s`;
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return s === 0 ? `${m}m` : `${m}m ${s}s`;
};

const formatDay = (dateStr: string): string => {
  const match = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return dateStr;
  const [, y, m, d] = match.map(Number);
  return dayFormatter.format(new Date(y, m - 1, d));
};

const maxViews = computed(() => {
  if (!ready.value || ready.value.daily.length === 0) return 0;
  return Math.max(0, ...ready.value.daily.map((d) => d.views));
});

// Rounds a raw step to a "nice" number (1, 2, 5 × 10ⁿ) for readable gridline values.
const niceStep = (rawStep: number): number => {
  const mag = 10 ** Math.floor(Math.log10(rawStep));
  const norm = rawStep / mag;
  const factor = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10;
  return factor * mag;
};

const gridLines = computed<number[]>(() => {
  const max = maxViews.value;
  if (max <= 0) return [];
  const step = Math.max(1, Math.round(niceStep(max / 3)));
  const scaleMax = Math.max(step, Math.ceil(max / step) * step + step);
  const lines: number[] = [];
  for (let v = step; v < scaleMax; v += step) {
    lines.push(v);
  }
  return lines;
});

const chartMaxViews = computed(() => {
  const max = maxViews.value;
  if (max <= 0) return 0;
  const step = Math.max(1, Math.round(niceStep(max / 3)));
  return Math.max(step, Math.ceil(max / step) * step + step);
});

const barHeightFor = (i: number): string => {
  if (!ready.value) return `${skeletonHeights[i]}%`;
  const day = ready.value.daily[i];
  if (!day || chartMaxViews.value === 0) return '0%';
  return `${(day.views / chartMaxViews.value) * 100}%`;
};
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
      <div class="observatory-usage__head">
        <div class="observatory-usage__col observatory-usage__col--title">
          <div class="observatory-usage__metric-header">usage</div>
          <svg v-if="!ready || ready.deltaDirection > 0" class="observatory-usage__arrow" viewBox="0 0 54 51"
            fill="currentColor" xmlns="http://www.w3.org/2000/svg">
            <path
              d="M23.1482 46.6372C23.1482 48.6461 24.7768 50.2746 26.7856 50.2746C28.7945 50.2746 30.4231 48.6461 30.4231 46.6372L26.7856 46.6372L23.1482 46.6372ZM29.3577 1.06517C27.9372 -0.355331 25.6341 -0.355331 24.2136 1.06517L1.06526 24.2135C-0.355243 25.634 -0.355243 27.9371 1.06526 29.3576C2.48575 30.7781 4.78883 30.7781 6.20933 29.3576L26.7856 8.78128L47.362 29.3576C48.7825 30.7781 51.0855 30.7781 52.506 29.3576C53.9265 27.9371 53.9265 25.634 52.506 24.2135L29.3577 1.06517ZM26.7856 46.6372L30.4231 46.6372L30.4231 3.63721L26.7856 3.63721L23.1482 3.63721L23.1482 46.6372L26.7856 46.6372Z" />
          </svg>
          <svg v-else-if="ready.deltaDirection < 0" class="observatory-usage__arrow observatory-usage__arrow--down"
            viewBox="0 0 54 51" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
            <path
              d="M23.1482 46.6372C23.1482 48.6461 24.7768 50.2746 26.7856 50.2746C28.7945 50.2746 30.4231 48.6461 30.4231 46.6372L26.7856 46.6372L23.1482 46.6372ZM29.3577 1.06517C27.9372 -0.355331 25.6341 -0.355331 24.2136 1.06517L1.06526 24.2135C-0.355243 25.634 -0.355243 27.9371 1.06526 29.3576C2.48575 30.7781 4.78883 30.7781 6.20933 29.3576L26.7856 8.78128L47.362 29.3576C48.7825 30.7781 51.0855 30.7781 52.506 29.3576C53.9265 27.9371 53.9265 25.634 52.506 24.2135L29.3577 1.06517ZM26.7856 46.6372L30.4231 46.6372L30.4231 3.63721L26.7856 3.63721L23.1482 3.63721L23.1482 46.6372L26.7856 46.6372Z" />
          </svg>
          <svg v-else class="observatory-usage__arrow" viewBox="0 0 54 51" fill="currentColor"
            xmlns="http://www.w3.org/2000/svg">
            <rect x="5" y="22" width="44" height="7" rx="3.5" />
          </svg>
        </div>

        <div class="observatory-usage__col">
          <div class="observatory-usage__metric-header">last 7 days</div>
          <div class="observatory-usage__metric-value">
            <CrossFade>
              <span v-if="ready" key="views-real">{{ formatNumber(ready.totalViews) }}</span>
              <SkeletonText v-else key="views-skel" variant="total" />
            </CrossFade>
          </div>
          <div class="observatory-usage__metric-label">views</div>
        </div>

        <div class="observatory-usage__col">
          <div class="observatory-usage__metric-header">&nbsp;</div>
          <div class="observatory-usage__metric-value">
            <CrossFade>
              <span v-if="ready" key="dur-real">{{ formatDuration(ready.avgDuration) }}</span>
              <SkeletonText v-else key="dur-skel" variant="total" />
            </CrossFade>
          </div>
          <div class="observatory-usage__metric-label">visit duration</div>
        </div>
      </div>

      <hr class="observatory-widget__divider" />

      <div class="observatory-usage__bars">
        <div class="observatory-usage__gridlines" aria-hidden="true">
          <div v-for="line in gridLines" :key="line" class="observatory-usage__gridline"
            :style="{ bottom: `${(line / chartMaxViews) * 100}%` }" />
        </div>

        <div v-for="i in 7" :key="i - 1" class="observatory-usage__bar-cell">
          <Tooltip class="observatory-usage__bar-slot" :text="ready && ready.daily[i - 1] ? `${formatNumber(ready.daily[i - 1].views)} views` : ''
            " :style="{ height: barHeightFor(i - 1) }">
            <div class="observatory-usage__bar" :class="{ 'observatory-usage__bar--skeleton': !ready }"
              :style="{ animationDelay: !ready ? `${(i - 1) * 80}ms` : undefined }"></div>
          </Tooltip>
        </div>

        <div class="observatory-usage__grid-labels" aria-hidden="true">
          <span v-for="line in gridLines" :key="line" class="observatory-usage__grid-label"
            :style="{ bottom: `${(line / chartMaxViews) * 100}%` }">{{ formatNumber(line) }}</span>
        </div>
      </div>

      <div class="observatory-usage__labels">
        <div v-for="i in 7" :key="i - 1" class="observatory-usage__label-cell">
          <CrossFade>
            <div v-if="ready" :key="`real-${i - 1}`" class="observatory-usage__label-content">
              <div class="observatory-usage__day">
                {{ ready.daily[i - 1] ? formatDay(ready.daily[i - 1].date) : '' }}
              </div>
            </div>
            <div v-else :key="`skel-${i - 1}`" class="observatory-usage__label-content">
              <SkeletonText variant="narrow" />
            </div>
          </CrossFade>
        </div>
      </div>
    </template>

    <template #footer>
      <div class="observatory-widget__footer observatory-usage__footer">
        <span class="observatory-usage__syncing" :class="{ 'observatory-usage__syncing--hidden': !data?._syncing }">
          syncing historical data…
        </span>
      </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.pane hr.observatory-widget__divider {
  margin-block: 1rem;
}

.observatory-usage__head {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) minmax(0, 1fr);
  column-gap: 2.5rem;
  align-items: start;
}

.observatory-usage__col {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.observatory-usage__metric-header {
  font-size: 1.25rem;
  margin-bottom: 0.5rem;
  white-space: nowrap;
}

.observatory-usage__arrow {
  width: 3.6rem;
  height: 3.6rem;
  margin-top: 0.25rem;
  color: var(--observatory-fg);
}

.observatory-usage__arrow--down {
  transform: rotate(180deg);
}

.observatory-usage__metric-value {
  font-size: 3.25rem;
  line-height: 1;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
}

.observatory-usage__metric-value> :deep(*) {
  grid-area: 1 / 1;
  min-width: 0;
}

.observatory-usage__metric-label {
  font-size: 0.875rem;
  margin-top: 0.25rem;
}

.observatory-usage__bars {
  --observatory-usage-axis-gutter: 1.6rem;

  position: relative;
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  column-gap: 1rem;
  align-items: end;
  height: 9rem;
  padding-inline-end: var(--observatory-usage-axis-gutter);
  border-bottom: 1px solid color-mix(in srgb, var(--observatory-fg) 45%, transparent);
  margin-bottom: 0.3rem;
}

.observatory-usage__gridlines,
.observatory-usage__grid-labels {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.observatory-usage__gridlines {
  z-index: 0;
}

.observatory-usage__gridline {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 1px dashed color-mix(in srgb, var(--observatory-fg) 20%, transparent);
}

.observatory-usage__grid-labels {
  z-index: 2;
}

.observatory-usage__grid-label {
  position: absolute;
  right: 0;
  transform: translateY(50%);
  font-size: 0.875rem;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  color: var(--observatory-fg);
  opacity: 0.55;
  background-color: var(--observatory-bg);
  padding: 0 3px;
  border-radius: 2px;
}

.observatory-usage__bar-cell {
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  align-items: center;
  height: 100%;
  gap: 3px;
}

.observatory-usage__bar-slot {
  width: 70%;
  min-height: 1px;
  transition: height 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.observatory-usage__bar {
  position: relative;
  z-index: 1;
  width: 100%;
  height: 100%;
  background-image: linear-gradient(180deg, var(--observatory-fg) 0%, var(--observatory-bar-bottom) 100%);
  opacity: 0.45;
  transition: opacity 0.4s ease;
}

.observatory-usage__bar::before {
  content: '';
  position: absolute;
  inset: 0;
  background-color: var(--observatory-fg);
  opacity: 0;
  transition: opacity 0.4s ease;
}

.observatory-usage__bar--skeleton::before {
  opacity: 1;
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-usage__labels {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  column-gap: 1rem;
  padding-inline-end: var(--observatory-usage-axis-gutter, 1.6rem);
}

.observatory-usage__label-cell {
  text-align: center;
  font-size: 0.95rem;
  line-height: 1.2;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
}

.observatory-usage__label-cell> :deep(*) {
  grid-area: 1 / 1;
  min-width: 0;
}

.observatory-usage__label-content {
  display: flex;
  flex-direction: column;
}

.observatory-usage__day {
  font-size: 14px;
  text-align: center;
  opacity: 0.7;
}

.observatory-usage__footer {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 0.5rem;
}

.observatory-usage__syncing--hidden {
  visibility: hidden;
}

@media (prefers-reduced-motion: reduce) {

  .observatory-usage__bar-slot,
  .observatory-usage__bar,
  .observatory-usage__bar::before {
    transition: none;
  }

  .observatory-usage__bar--skeleton::before {
    animation: none;
    opacity: 0.3;
  }
}

@container observatory-pane (max-width: 440px) {
  .observatory-usage__head {
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-areas:
      'title views'
      'title duration';
    row-gap: 1rem;
    column-gap: 1.5rem;
  }

  .observatory-usage__col--title {
    grid-area: title;
  }
}

@container observatory-pane (max-width: 320px) {
  .observatory-usage__head {
    grid-template-columns: 1fr;
    grid-template-areas:
      'title'
      'views'
      'duration';
  }

  .observatory-usage__metric-value {
    font-size: 3rem;
  }
}
</style>

<style>
div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryUsageWidget'] .widget-heading {
  display: none;
}

div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryUsageWidget'] .pane {
  --pane-padding: 16px;
}
</style>

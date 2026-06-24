<script setup lang="ts">
import { computed, onUnmounted, watch } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import SkeletonText from '@/shared/SkeletonText.vue';
import CrossFade from '@/shared/CrossFade.vue';
import StatusNotice, { type UmamiStatus } from '@/shared/StatusNotice.vue';
import Tooltip from '@/shared/Tooltip.vue';
import CountryMap from '@/shared/CountryMap.vue';
import { useWidgetData } from '@/shared/useWidgetData';

interface TopMetric {
  x: string;
  y: number;
}

interface DailyEntry {
  date: string;
  visitors: number;
  queued?: boolean;
}

interface Visitors {
  totalVisitors: number;
  priorVisitors: number;
  deltaPercent: number;
  deltaDirection: number;
  daily: DailyEntry[];
  top: {
    country: TopMetric | null;
    referrer: TopMetric | null;
    browser: TopMetric | null;
  };
  countries: TopMetric[];
  _status?: UmamiStatus;
  _syncing?: boolean;
}

const hasError = (s: UmamiStatus | undefined): boolean => !!s && (!s.configured || !s.apiKeyValid);

const props = defineProps<{
  locale?: string;
}>();

const skeletonHeights = [55, 35, 48, 70, 100, 28, 60];

const { data, refetch } = useWidgetData<Visitors>('umami-is/dashboard/get-widget-summary');

const ready = computed(() => (data.value && !data.value._syncing ? data.value : null));

let pollTimer: ReturnType<typeof setInterval> | null = null;

watch(
  () => data.value?._syncing,
  (syncing) => {
    if (syncing) {
      fetch(window.Craft.getActionUrl('queue/run'), { credentials: 'include' }).catch(() => {});
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

import { resolveCountryName } from '@/shared/resolveCountryName';

const topCountry = computed(() => {
  const code = ready.value?.top.country?.x;
  return resolveCountryName(code, localeId, '—');
});

const formatNumber = (n: number) => numberFormatter.format(n);

const formatDay = (dateStr: string): string => {
  const match = dateStr.match(/^(\d{4})-(\d{2})-(\d{2})/);
  if (!match) return dateStr;
  const [, y, m, d] = match.map(Number);
  return dayFormatter.format(new Date(y, m - 1, d));
};

const maxVisitors = computed(() => {
  if (!ready.value || ready.value.daily.length === 0) return 0;
  return Math.max(0, ...ready.value.daily.map((d) => d.visitors));
});

// Rounds a raw step to a "nice" number (1, 2, 5 × 10ⁿ) for readable gridline values.
const niceStep = (rawStep: number): number => {
  const mag = 10 ** Math.floor(Math.log10(rawStep));
  const norm = rawStep / mag;
  const factor = norm <= 1 ? 1 : norm <= 2 ? 2 : norm <= 5 ? 5 : 10;
  return factor * mag;
};

// Horizontal reference lines at nice round values below the peak. Aiming for ~3
// intervals (so usually 1–2 internal lines) keeps it sparse, like the GA reference.
// Shares maxVisitors with the bars, so lines and bar tops are on the same scale.
const gridLines = computed<number[]>(() => {
  const max = maxVisitors.value;
  if (max <= 0) return [];
  const step = Math.max(1, Math.round(niceStep(max / 3)));
  const lines: number[] = [];
  for (let v = step; v < max; v += step) {
    lines.push(v);
  }
  return lines;
});

const barHeightFor = (i: number): string => {
  if (!ready.value) return `${skeletonHeights[i]}%`;
  const day = ready.value.daily[i];
  if (!day || maxVisitors.value === 0) return '0%';
  return `${(day.visitors / maxVisitors.value) * 100}%`;
};
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
      <div class="umami-visitors__head">
        <div class="umami-visitors__col umami-visitors__col--visitors">
          <div class="umami-visitors__header">visitors</div>
          <svg
            v-if="!ready || ready.deltaDirection > 0"
            class="umami-visitors__arrow"
            viewBox="0 0 54 51"
            fill="currentColor"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              d="M23.1482 46.6372C23.1482 48.6461 24.7768 50.2746 26.7856 50.2746C28.7945 50.2746 30.4231 48.6461 30.4231 46.6372L26.7856 46.6372L23.1482 46.6372ZM29.3577 1.06517C27.9372 -0.355331 25.6341 -0.355331 24.2136 1.06517L1.06526 24.2135C-0.355243 25.634 -0.355243 27.9371 1.06526 29.3576C2.48575 30.7781 4.78883 30.7781 6.20933 29.3576L26.7856 8.78128L47.362 29.3576C48.7825 30.7781 51.0855 30.7781 52.506 29.3576C53.9265 27.9371 53.9265 25.634 52.506 24.2135L29.3577 1.06517ZM26.7856 46.6372L30.4231 46.6372L30.4231 3.63721L26.7856 3.63721L23.1482 3.63721L23.1482 46.6372L26.7856 46.6372Z"
            />
          </svg>
          <svg
            v-else-if="ready.deltaDirection < 0"
            class="umami-visitors__arrow umami-visitors__arrow--down"
            viewBox="0 0 54 51"
            fill="currentColor"
            xmlns="http://www.w3.org/2000/svg"
          >
            <path
              d="M23.1482 46.6372C23.1482 48.6461 24.7768 50.2746 26.7856 50.2746C28.7945 50.2746 30.4231 48.6461 30.4231 46.6372L26.7856 46.6372L23.1482 46.6372ZM29.3577 1.06517C27.9372 -0.355331 25.6341 -0.355331 24.2136 1.06517L1.06526 24.2135C-0.355243 25.634 -0.355243 27.9371 1.06526 29.3576C2.48575 30.7781 4.78883 30.7781 6.20933 29.3576L26.7856 8.78128L47.362 29.3576C48.7825 30.7781 51.0855 30.7781 52.506 29.3576C53.9265 27.9371 53.9265 25.634 52.506 24.2135L29.3577 1.06517ZM26.7856 46.6372L30.4231 46.6372L30.4231 3.63721L26.7856 3.63721L23.1482 3.63721L23.1482 46.6372L26.7856 46.6372Z"
            />
          </svg>
          <svg
            v-else
            class="umami-visitors__arrow"
            viewBox="0 0 54 51"
            fill="currentColor"
            xmlns="http://www.w3.org/2000/svg"
          >
            <rect x="5" y="22" width="44" height="7" rx="3.5" />
          </svg>
        </div>

        <div class="umami-visitors__col umami-visitors__col--total">
          <div class="umami-visitors__header">last 7 days</div>
          <div class="umami-visitors__total-count">
            <CrossFade>
              <span v-if="ready" key="total-real">{{ formatNumber(ready.totalVisitors) }}</span>
              <SkeletonText v-else key="total-skel" variant="total" />
            </CrossFade>
          </div>
        </div>

        <div class="umami-visitors__col umami-visitors__col--top">
          <div class="umami-visitors__header">top</div>
          <div class="umami-visitors__top-grid">
            <div>country</div>
            <div class="umami-visitors__top-value">
              <CrossFade>
                <span v-if="ready" key="country-real">{{ topCountry }}</span>
                <SkeletonText v-else key="country-skel" />
              </CrossFade>
            </div>
            <div>referrers</div>
            <div class="umami-visitors__top-value">
              <CrossFade>
                <span v-if="ready" key="ref-real">{{ ready.top.referrer?.x ?? '—' }}</span>
                <SkeletonText v-else key="ref-skel" />
              </CrossFade>
            </div>
            <div>browser</div>
            <div class="umami-visitors__top-value">
              <CrossFade>
                <span v-if="ready" key="br-real">{{ ready.top.browser?.x ?? '—' }}</span>
                <SkeletonText v-else key="br-skel" />
              </CrossFade>
            </div>
          </div>
        </div>
      </div>

      <hr class="umami-widget__divider" />

      <div class="umami-visitors__bars">
        <div class="umami-visitors__gridlines" aria-hidden="true">
          <div
            v-for="line in gridLines"
            :key="line"
            class="umami-visitors__gridline"
            :style="{ bottom: `${(line / maxVisitors) * 100}%` }"
          />
        </div>

        <div v-for="i in 7" :key="i - 1" class="umami-visitors__bar-cell">
          <Tooltip
            class="umami-visitors__bar-slot"
            :text="
              ready && ready.daily[i - 1]
                ? `${formatNumber(ready.daily[i - 1].visitors)} visitors`
                : ''
            "
            :style="{ height: barHeightFor(i - 1) }"
          >
            <div
              class="umami-visitors__bar"
              :class="{ 'umami-visitors__bar--skeleton': !ready }"
              :style="{ animationDelay: !ready ? `${(i - 1) * 80}ms` : undefined }"
            ></div>
          </Tooltip>
        </div>

        <div class="umami-visitors__grid-labels" aria-hidden="true">
          <span
            v-for="line in gridLines"
            :key="line"
            class="umami-visitors__grid-label"
            :style="{ bottom: `${(line / maxVisitors) * 100}%` }"
            >{{ formatNumber(line) }}</span
          >
        </div>
      </div>

      <div class="umami-visitors__labels">
        <div v-for="i in 7" :key="i - 1" class="umami-visitors__label-cell">
          <CrossFade>
            <div v-if="ready" :key="`real-${i - 1}`" class="umami-visitors__label-content">
              <div class="umami-visitors__day">
                {{ ready.daily[i - 1] ? formatDay(ready.daily[i - 1].date) : '' }}
              </div>
            </div>
            <div v-else :key="`skel-${i - 1}`" class="umami-visitors__label-content">
              <SkeletonText variant="narrow" />
            </div>
          </CrossFade>
        </div>
      </div>

      <div class="umami-visitors__map">
        <CountryMap :countries="data?.countries" :locale="localeId" />
      </div>
    </template>

    <template #footer>
      <div class="umami-widget__footer umami-visitors__footer">
        <span
          class="umami-visitors__syncing"
          :class="{ 'umami-visitors__syncing--hidden': !data?._syncing }"
        >
          syncing historical data…
        </span>
        <span>powered by analytics source</span>
      </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.umami-visitors__head {
  display: grid;
  grid-template-columns: auto auto minmax(0, 1fr);
  column-gap: 2.5rem;
  align-items: start;
}

.umami-visitors__col {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.umami-visitors__header {
  font-size: 1.25rem;
  margin-bottom: 0.5rem;
}

.umami-visitors__arrow {
  width: 3rem;
  height: 3rem;
  margin-top: 0.25rem;
  color: var(--umami-fg);
}

.umami-visitors__arrow--down {
  transform: rotate(180deg);
}

.umami-visitors__total-count {
  font-size: 3.75rem;
  line-height: 1;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
}

.umami-visitors__total-count > :deep(*) {
  grid-area: 1 / 1;
  min-width: 0;
}

.umami-visitors__top-grid {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  column-gap: 1rem;
  row-gap: 0.25rem;
  line-height: 1.2;
}

.umami-visitors__top-value {
  min-width: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
}

.umami-visitors__top-value > :deep(*) {
  grid-area: 1 / 1;
  min-width: 0;
}

.umami-visitors__bars {
  position: relative;
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  column-gap: 1rem;
  align-items: end;
  height: 9rem;
  border-bottom: 1px solid color-mix(in srgb, var(--umami-fg) 45%, transparent);
  margin-bottom: 0.3rem;
}

/* Horizontal reference lines (behind bars) + value labels (in front). */
.umami-visitors__gridlines,
.umami-visitors__grid-labels {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.umami-visitors__gridlines {
  z-index: 0;
}

.umami-visitors__gridline {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 1px dashed color-mix(in srgb, var(--umami-fg) 20%, transparent);
}

.umami-visitors__grid-labels {
  z-index: 2;
}

.umami-visitors__grid-label {
  position: absolute;
  right: 0;
  transform: translateY(50%);
  font-size: 12px;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  color: var(--umami-fg);
  opacity: 0.55;
  background-color: var(--umami-bg);
  padding: 0 3px;
  border-radius: 2px;
}

.umami-visitors__bar-cell {
  display: flex;
  flex-direction: column;
  justify-content: flex-end;
  align-items: center;
  height: 100%;
  gap: 3px;
}

.umami-visitors__bar-slot {
  width: 70%;
  min-height: 1px;
  transition: height 0.6s cubic-bezier(0.4, 0, 0.2, 1);
}

.umami-visitors__bar {
  position: relative;
  z-index: 1;
  width: 100%;
  height: 100%;
  background-image: linear-gradient(180deg, var(--umami-fg) 0%, var(--umami-bar-bottom) 100%);
  opacity: 0.45;
  transition: opacity 0.4s ease;
}

.umami-visitors__bar::before {
  content: '';
  position: absolute;
  inset: 0;
  background-color: var(--umami-fg);
  opacity: 0;
  transition: opacity 0.4s ease;
}

.umami-visitors__bar--skeleton::before {
  opacity: 1;
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-visitors__labels {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  column-gap: 1rem;
}

.umami-visitors__label-cell {
  text-align: center;
  font-size: 0.95rem;
  line-height: 1.2;
  display: grid;
  grid-template-columns: minmax(0, 1fr);
}

.umami-visitors__label-cell > :deep(*) {
  grid-area: 1 / 1;
  min-width: 0;
}

.umami-visitors__label-content {
  display: flex;
  flex-direction: column;
}

.umami-visitors__day {
  font-size: 12px;
  text-align: center;
  opacity: 0.7;
}

.umami-visitors__map {
  margin-top: 1.25rem;
}

.umami-visitors__footer {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  margin-top: 0.5rem;
}

.umami-visitors__syncing--hidden {
  visibility: hidden;
}

@media (prefers-reduced-motion: reduce) {
  .umami-visitors__bar-slot,
  .umami-visitors__bar,
  .umami-visitors__bar::before {
    transition: none;
  }
  .umami-visitors__bar--skeleton::before {
    animation: none;
    opacity: 0.3;
  }
}

@container umami-pane (max-width: 440px) {
  .umami-visitors__head {
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-areas:
      'visitors total'
      'top top';
    row-gap: 1rem;
    column-gap: 1.5rem;
  }
  .umami-visitors__col--visitors {
    grid-area: visitors;
  }
  .umami-visitors__col--total {
    grid-area: total;
  }
  .umami-visitors__col--top {
    grid-area: top;
  }
}

@container umami-pane (max-width: 320px) {
  .umami-visitors__head {
    grid-template-columns: 1fr;
    grid-template-areas:
      'visitors'
      'total'
      'top';
  }
  .umami-visitors__total-count {
    font-size: 3rem;
  }
}
</style>

<style>
div[data-type='szenario\\craftumamiis\\widgets\\UmamiIsVisitorsWidget'] .widget-heading {
  display: none;
}
</style>

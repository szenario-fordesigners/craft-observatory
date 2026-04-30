<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';

interface TopMetric {
  x: string;
  y: number;
}

interface DailyEntry {
  date: string;
  visitors: number;
}

interface WidgetSummary {
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
}

const props = defineProps<{
  locale?: string;
}>();

const data = ref<WidgetSummary | null>(null);
const skeletonHeights = [55, 35, 48, 70, 100, 28, 60];

const localeId = props.locale || 'en';
const weekdayFormatter = new Intl.DateTimeFormat(localeId, { weekday: 'short', timeZone: 'UTC' });
const dayFormatter = new Intl.DateTimeFormat(localeId, { day: 'numeric', timeZone: 'UTC' });
const numberFormatter = new Intl.NumberFormat(localeId);

const toUtcDate = (dateStr: string): Date => {
  const [y, m, d] = dateStr.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d));
};

const formatWeekday = (dateStr: string): string =>
  weekdayFormatter.format(toUtcDate(dateStr)).replace(/\.$/, '');

const formatDay = (dateStr: string): string => dayFormatter.format(toUtcDate(dateStr));

const formatNumber = (n: number) => numberFormatter.format(n);

const maxVisitors = computed(() => {
  if (!data.value || data.value.daily.length === 0) return 0;
  return Math.max(0, ...data.value.daily.map((d) => d.visitors));
});

const barHeightFor = (i: number): string => {
  if (!data.value) return `${skeletonHeights[i]}%`;
  const day = data.value.daily[i];
  if (!day || maxVisitors.value === 0) return '0%';
  return `${(day.visitors / maxVisitors.value) * 100}%`;
};

let abortController: AbortController | null = null;

const fetchSummary = async () => {
  abortController?.abort();
  abortController = new AbortController();
  try {
    const url = window.Craft.getActionUrl('umami-is/dashboard/get-widget-summary');
    const res = await fetch(url, {
      headers: { Accept: 'application/json' },
      signal: abortController.signal,
    });
    if (res.ok) {
      data.value = (await res.json()) as WidgetSummary;
    }
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') return;
    console.error('Error fetching widget summary', e);
  }
};

onMounted(fetchSummary);
onUnmounted(() => abortController?.abort());
</script>

<template>
  <div class="umami-widget">
    <div class="umami-widget__head">
      <div class="umami-widget__col umami-widget__col--visitors">
        <div class="umami-widget__header">visitors</div>
        <svg
          v-if="!data || data.deltaDirection > 0"
          class="umami-widget__arrow"
          viewBox="17 45 56 47"
          fill="currentColor"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M47.572 45.928C46.1515 44.5075 43.8485 44.5075 42.428 45.928L19.2796 69.0763C17.8591 70.4968 17.8591 72.7999 19.2796 74.2204C20.7001 75.6409 23.0032 75.6409 24.4237 74.2204L45 53.6441L65.5763 74.2204C66.9968 75.6409 69.2999 75.6409 70.7204 74.2204C72.1409 72.7999 72.1409 70.4968 70.7204 69.0763L47.572 45.928ZM45 91.5L48.6374 91.5L48.6374 48.5L45 48.5L41.3626 48.5L41.3626 91.5L45 91.5Z"
          />
        </svg>
        <svg
          v-else-if="data.deltaDirection < 0"
          class="umami-widget__arrow umami-widget__arrow--down"
          viewBox="17 45 56 47"
          fill="currentColor"
          xmlns="http://www.w3.org/2000/svg"
        >
          <path
            d="M47.572 45.928C46.1515 44.5075 43.8485 44.5075 42.428 45.928L19.2796 69.0763C17.8591 70.4968 17.8591 72.7999 19.2796 74.2204C20.7001 75.6409 23.0032 75.6409 24.4237 74.2204L45 53.6441L65.5763 74.2204C66.9968 75.6409 69.2999 75.6409 70.7204 74.2204C72.1409 72.7999 72.1409 70.4968 70.7204 69.0763L47.572 45.928ZM45 91.5L48.6374 91.5L48.6374 48.5L45 48.5L41.3626 48.5L41.3626 91.5L45 91.5Z"
          />
        </svg>
        <svg
          v-else
          class="umami-widget__arrow"
          viewBox="17 45 56 47"
          fill="currentColor"
          xmlns="http://www.w3.org/2000/svg"
        >
          <rect x="22" y="65" width="46" height="7" rx="3.5" />
        </svg>
      </div>

      <div class="umami-widget__col umami-widget__col--total">
        <div class="umami-widget__header">last 7 days</div>
        <div class="umami-widget__total-count">
          <Transition name="umami-fade" mode="out-in">
            <span v-if="data" key="total-real">{{ formatNumber(data.totalVisitors) }}</span>
            <span
              v-else
              key="total-skel"
              class="umami-widget__skeleton-text umami-widget__skeleton-text--total"
            ></span>
          </Transition>
        </div>
      </div>

      <div class="umami-widget__col umami-widget__col--top">
        <div class="umami-widget__header">top</div>
        <div class="umami-widget__top-grid">
          <div>country</div>
          <div class="umami-widget__top-value">
            <Transition name="umami-fade" mode="out-in">
              <span v-if="data" key="country-real">{{ data.top.country?.x ?? '—' }}</span>
              <span v-else key="country-skel" class="umami-widget__skeleton-text"></span>
            </Transition>
          </div>
          <div>referrers</div>
          <div class="umami-widget__top-value">
            <Transition name="umami-fade" mode="out-in">
              <span v-if="data" key="ref-real">{{ data.top.referrer?.x ?? '—' }}</span>
              <span v-else key="ref-skel" class="umami-widget__skeleton-text"></span>
            </Transition>
          </div>
          <div>browser</div>
          <div class="umami-widget__top-value">
            <Transition name="umami-fade" mode="out-in">
              <span v-if="data" key="br-real">{{ data.top.browser?.x ?? '—' }}</span>
              <span v-else key="br-skel" class="umami-widget__skeleton-text"></span>
            </Transition>
          </div>
        </div>
      </div>
    </div>

    <hr class="umami-widget__divider" />

    <div class="umami-widget__bars">
      <div v-for="i in 7" :key="i - 1" class="umami-widget__bar-cell">
        <div
          class="umami-widget__bar"
          :class="{ 'umami-widget__bar--skeleton': !data }"
          :style="{
            height: barHeightFor(i - 1),
            animationDelay: !data ? `${(i - 1) * 80}ms` : undefined,
          }"
        ></div>
      </div>
    </div>

    <div class="umami-widget__labels">
      <div v-for="i in 7" :key="i - 1" class="umami-widget__label-cell">
        <Transition name="umami-fade" mode="out-in">
          <div v-if="data" :key="`real-${i - 1}`" class="umami-widget__label-content">
            <div class="umami-widget__weekday">
              {{ data.daily[i - 1] ? formatWeekday(data.daily[i - 1].date) : '' }}
            </div>
            <div class="umami-widget__day">
              {{ data.daily[i - 1] ? formatDay(data.daily[i - 1].date) : '' }}
            </div>
          </div>
          <div v-else :key="`skel-${i - 1}`" class="umami-widget__label-content">
            <div class="umami-widget__skeleton-text umami-widget__skeleton-text--narrow"></div>
            <div class="umami-widget__skeleton-text umami-widget__skeleton-text--narrow"></div>
          </div>
        </Transition>
      </div>
    </div>

    <div class="umami-widget__footer">powered by Umami</div>
  </div>
</template>

<style>
:root {
  --umami-bg: #eeebb0;
  --umami-fg: #72715a;
  --umami-bar-bottom: #d8d6ab;
}

.pane:has(.umami-widget) {
  background-color: var(--umami-bg) !important;
  border-radius: 1.25rem !important;
  container-type: inline-size;
  container-name: umami-pane;
}

.umami-widget {
  width: 100%;
  color: var(--umami-fg);
  font-family: 'Arial', sans-serif;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.umami-widget__head {
  display: grid;
  grid-template-columns: auto auto minmax(0, 1fr);
  column-gap: 2.5rem;
  align-items: start;
}

.umami-widget__col {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.umami-widget__header {
  font-size: 1.25rem;
  margin-bottom: 0.5rem;
}

.umami-widget__arrow {
  width: 3rem;
  height: 3rem;
  margin-top: 0.25rem;
  color: var(--umami-fg);
}

.umami-widget__arrow--down {
  transform: rotate(180deg);
}

.umami-widget__total-count {
  font-size: 3.75rem;
  line-height: 1;
}

.umami-widget__top-grid {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  column-gap: 1rem;
  row-gap: 0.25rem;
  line-height: 1.2;
}

.umami-widget__top-value {
  min-width: 0;
}

.umami-widget__divider {
  border: 0;
  border-top: 1px solid var(--umami-fg);
  opacity: 0.45;
  margin: 0;
}

.umami-widget__bars {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  column-gap: 1rem;
  align-items: end;
  height: 9rem;
  border-bottom: 1px solid color-mix(in srgb, var(--umami-fg) 45%, transparent);
  margin-bottom: 0.5rem;
}

.umami-widget__bar-cell {
  display: flex;
  justify-content: center;
  align-items: end;
  height: 100%;
}

.umami-widget__bar {
  position: relative;
  width: 70%;
  min-height: 1px;
  background-image: linear-gradient(180deg, var(--umami-fg) 0%, var(--umami-bar-bottom) 100%);
  opacity: 0.45;
  transition:
    height 0.6s cubic-bezier(0.4, 0, 0.2, 1),
    opacity 0.4s ease;
}

.umami-widget__bar::before {
  content: '';
  position: absolute;
  inset: 0;
  background-color: var(--umami-fg);
  opacity: 0;
  transition: opacity 0.4s ease;
}

.umami-widget__bar--skeleton::before {
  opacity: 1;
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-widget__skeleton-text {
  display: inline-block;
  width: 100%;
  max-width: 8rem;
  height: 0.75em;
  border-radius: 3px;
  background-color: var(--umami-fg);
  opacity: 0.45;
  animation: umami-text-pulse 1.4s ease-in-out infinite;
  vertical-align: middle;
}

.umami-widget__skeleton-text--total {
  height: 0.7em;
  max-width: 6ch;
}

.umami-widget__skeleton-text--narrow {
  max-width: 1.75rem;
  height: 0.7em;
  margin: 0 auto;
}

@keyframes umami-bar-pulse {
  0%,
  100% {
    opacity: 1;
  }
  50% {
    opacity: 0.4;
  }
}

@keyframes umami-text-pulse {
  0%,
  100% {
    opacity: 0.45;
  }
  50% {
    opacity: 0.15;
  }
}

.umami-fade-enter-active,
.umami-fade-leave-active {
  transition: opacity 0.35s ease;
}

.umami-fade-enter-from,
.umami-fade-leave-to {
  opacity: 0;
}

@media (prefers-reduced-motion: reduce) {
  .umami-widget__bar,
  .umami-widget__bar::before,
  .umami-fade-enter-active,
  .umami-fade-leave-active {
    transition: none;
  }
  .umami-widget__bar--skeleton::before,
  .umami-widget__skeleton-text {
    animation: none;
    opacity: 0.3;
  }
}

.umami-widget__labels {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  column-gap: 1rem;
}

.umami-widget__label-cell {
  text-align: center;
  font-size: 0.95rem;
  line-height: 1.2;
}

.umami-widget__label-content {
  display: flex;
  flex-direction: column;
  gap: 0.125rem;
}

.umami-widget__footer {
  text-align: right;
  font-size: 0.75rem;
  opacity: 0.7;
}

@container umami-pane (max-width: 440px) {
  .umami-widget__head {
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-areas:
      'visitors total'
      'top top';
    row-gap: 1rem;
    column-gap: 1.5rem;
  }
  .umami-widget__col--visitors {
    grid-area: visitors;
  }
  .umami-widget__col--total {
    grid-area: total;
  }
  .umami-widget__col--top {
    grid-area: top;
  }
}

@container umami-pane (max-width: 320px) {
  .umami-widget__head {
    grid-template-columns: 1fr;
    grid-template-areas:
      'visitors'
      'total'
      'top';
  }
  .umami-widget__total-count {
    font-size: 3rem;
  }
}
</style>

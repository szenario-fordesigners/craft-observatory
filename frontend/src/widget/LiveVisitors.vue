<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import StatusNotice from '@/shared/StatusNotice.vue';
import type { AnalyticsStatus } from '@/shared/analyticsTypes';
import { useWidgetData } from '@/shared/useWidgetData';

const props = defineProps<{
  locale?: string;
}>();

interface ActiveResponse {
  visitors: number;
  _status?: AnalyticsStatus;
}

const hasError = (s: AnalyticsStatus | undefined): boolean => !!s && (!s.configured || !s.apiKeyValid);

const { data, refetch } = useWidgetData<ActiveResponse>('observatory/dashboard/get-active-visitors');

// Refresh the live count once a minute. Mirrors the /active upstream cache window.
const REFRESH_MS = 60_000;
let pollTimer: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
  pollTimer = setInterval(refetch, REFRESH_MS);
});

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer);
});

const numberFormatter = new Intl.NumberFormat(props.locale || 'en');

// First load shows the skeleton; subsequent polls keep the prior number on screen.
const ready = computed(() => data.value !== null);
const visitors = computed(() => numberFormatter.format(data.value?.visitors ?? 0));

// Shrink the count to keep larger numbers inside the circle: 100px up to 2 digits,
// 80px at 3, 70px at 4+.
const countFontSize = computed(() => {
  const digits = (data.value?.visitors ?? 0).toString().length;
  if (digits <= 2) return '100px';
  if (digits === 3) return '80px';
  return '70px';
});

// The ring orbits faster the busier the site is. Speed ramps linearly with
// visitors and saturates at SPEED_CAP, so beyond that it can't whip around too fast.
const SPEED_CAP = 30;
const SLOW_SECONDS = 12; // one lap at 0 visitors
const FAST_SECONDS = 2; // one lap at the cap and above

// Each dot laps at its own pace, so the ring drifts out of formation and bunches up
// instead of turning as one rigid wheel. Deterministic per index (a sine hash, not
// Math.random) so a dot keeps its pace across re-renders as the count changes.
const PACE_SPREAD = 0.45; // ±22% around the base lap time
const paceFor = (i: number): number => 1 - PACE_SPREAD / 2 + ((Math.sin(i * 127.1) + 1) / 2) * PACE_SPREAD;

// One orbiting dot per visitor, evenly spaced, capped so the ring stays readable.
const DOT_CAP = 20;
const dots = computed(() => {
  const visitors = data.value?.visitors ?? 0;
  const count = Math.min(visitors, DOT_CAP);
  const fraction = Math.min(visitors, SPEED_CAP) / SPEED_CAP;
  const baseSeconds = SLOW_SECONDS - fraction * (SLOW_SECONDS - FAST_SECONDS);

  return Array.from({ length: count }, (_, i) => ({
    angle: `${(i * 360) / count}deg`,
    duration: `${(baseSeconds * paceFor(i)).toFixed(2)}s`,
  }));
});
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
      <div class="observatory-live__header">live</div>

      <div class="observatory-live__stage">
        <div class="observatory-live__circle" :class="{ 'observatory-live__circle--skeleton': !ready }">
          <CrossFade>
            <span
              v-if="ready"
              key="count"
              class="observatory-live__count"
              :style="{ fontSize: countFontSize }"
              >{{ visitors }}</span
            >
            <span v-else key="skel" class="observatory-live__count observatory-live__count--skeleton">&nbsp;</span>
          </CrossFade>
        </div>

        <div v-if="dots.length" class="observatory-live__orbit" aria-hidden="true">
          <span
            v-for="(dot, i) in dots"
            :key="i"
            class="observatory-live__spoke"
            :style="{ '--observatory-live-angle': dot.angle, animationDuration: dot.duration }"
          ></span>
        </div>
      </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.observatory-live__header {
  font-size: 1.25rem;
  line-height: 1;
}

.observatory-live__stage {
  /* Shared geometry so the circle and its orbit stay concentric. The satellite
     rides at --observatory-live-gap beyond the circle edge, a fixed distance regardless
     of the circle's responsive size. */
  --observatory-live-circle: min(62%, 11rem);
  --observatory-live-satellite: 0.9rem;
  --observatory-live-gap: 1.6rem;

  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  /* The stage only sizes to the circle — the orbit is absolute — so reserve exactly the
     dots' reach past its edge: the gap plus the dot's own overhanging half. */
  padding: calc(var(--observatory-live-gap) + var(--observatory-live-satellite) / 2) 0;
}

.observatory-live__circle {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  place-items: center;
  width: var(--observatory-live-circle);
  aspect-ratio: 1;
  border-radius: 50%;
  background-color: var(--observatory-fg);
  color: var(--observatory-bg);
}

.observatory-live__circle--skeleton {
  animation: observatory-bar-pulse 1.4s ease-in-out infinite;
}

.observatory-live__count {
  font-weight: 500;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  grid-area: 1 / 1;
}

.observatory-live__count--skeleton {
  visibility: hidden;
}

/* A square box centered on the circle, holding the dots. Its width is sized so that a
   dot — riding the box's top edge — sits exactly --observatory-live-gap beyond the
   circle's edge, tracing a concentric path at a fixed distance. */
.observatory-live__orbit {
  position: absolute;
  top: 50%;
  left: 50%;
  width: calc(var(--observatory-live-circle) + 2 * var(--observatory-live-gap) + var(--observatory-live-satellite));
  aspect-ratio: 1;
  transform: translate(-50%, -50%);
  pointer-events: none;
}

/* Each spoke fills the orbit box and carries one dot on its top edge, so spinning the
   spoke walks the dot around the orbit. Its start angle and lap time come in inline,
   per dot, which is what keeps the ring from turning as one rigid wheel.
   ponytail: percentage-free — --observatory-live-circle is a % of the stage, which would
   resolve against the element's own size inside a translate(). */
.observatory-live__spoke {
  position: absolute;
  inset: 0;
  transform: rotate(var(--observatory-live-angle));
  animation-name: observatory-orbit;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}

.observatory-live__spoke::before {
  content: '';
  position: absolute;
  top: 0;
  left: 50%;
  width: var(--observatory-live-satellite);
  height: var(--observatory-live-satellite);
  border-radius: 50%;
  background-color: var(--observatory-fg);
  transform: translate(-50%, -50%);
}

/* Starts from the spoke's own angle so each dot laps from where it sits. */
@keyframes observatory-orbit {
  from {
    transform: rotate(var(--observatory-live-angle));
  }
  to {
    transform: rotate(calc(var(--observatory-live-angle) + 360deg));
  }
}

/* Animation off leaves the static rotate() above, so the dots stay spread around the ring. */
@media (prefers-reduced-motion: reduce) {
  .observatory-live__circle--skeleton,
  .observatory-live__spoke {
    animation: none;
  }
}
</style>

<style>
div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryLiveVisitorsWidget'] .widget-heading {
  display: none;
}

div[data-type='szenario\\craftobservatory\\widgets\\ObservatoryLiveVisitorsWidget'] .pane {
  --pane-padding: 16px;
}
</style>

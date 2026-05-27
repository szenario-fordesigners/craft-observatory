<script setup lang="ts">
import { computed, onMounted, onUnmounted } from 'vue';
import WidgetFrame from '@/shared/WidgetFrame.vue';
import CrossFade from '@/shared/CrossFade.vue';
import StatusNotice, { type UmamiStatus } from '@/shared/StatusNotice.vue';
import { useWidgetData } from '@/shared/useWidgetData';

const props = defineProps<{
  locale?: string;
}>();

interface ActiveResponse {
  visitors: number;
  _status?: UmamiStatus;
}

const hasError = (s: UmamiStatus | undefined): boolean => !!s && (!s.configured || !s.apiKeyValid);

const { data, refetch } = useWidgetData<ActiveResponse>('umami-is/dashboard/get-active-visitors');

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

// The satellite orbits faster the busier the site is. Speed ramps linearly with
// visitors and saturates at SPEED_CAP, so beyond that it can't whip around too fast.
const SPEED_CAP = 30;
const SLOW_SECONDS = 12; // one lap at 0 visitors
const FAST_SECONDS = 2; // one lap at the cap and above
const orbitDuration = computed(() => {
  const fraction = Math.min(data.value?.visitors ?? 0, SPEED_CAP) / SPEED_CAP;
  return `${SLOW_SECONDS - fraction * (SLOW_SECONDS - FAST_SECONDS)}s`;
});
</script>

<template>
  <WidgetFrame>
    <StatusNotice v-if="hasError(data?._status)" :status="data?._status" variant="widget" />

    <template v-else>
      <div class="umami-live__header">live</div>

      <div class="umami-live__stage">
        <div class="umami-live__circle" :class="{ 'umami-live__circle--skeleton': !ready }">
          <CrossFade>
            <span
              v-if="ready"
              key="count"
              class="umami-live__count"
              :style="{ fontSize: countFontSize }"
              >{{ visitors }}</span
            >
            <span v-else key="skel" class="umami-live__count umami-live__count--skeleton">&nbsp;</span>
          </CrossFade>
        </div>

        <div class="umami-live__orbit" :style="{ animationDuration: orbitDuration }" aria-hidden="true">
          <span class="umami-live__satellite"></span>
        </div>
      </div>
    </template>
  </WidgetFrame>
</template>

<style scoped>
.umami-live__header {
  font-size: 1.25rem;
  line-height: 1;
}

.umami-live__stage {
  /* Shared geometry so the circle and its orbit stay concentric. The satellite
     rides at --umami-live-gap beyond the circle edge, a fixed distance regardless
     of the circle's responsive size. */
  --umami-live-circle: min(62%, 11rem);
  --umami-live-satellite: 0.9rem;
  --umami-live-gap: 1.6rem;

  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.5rem 0;
}

.umami-live__circle {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  place-items: center;
  width: var(--umami-live-circle);
  aspect-ratio: 1;
  border-radius: 50%;
  background-color: var(--umami-fg);
  color: var(--umami-bg);
}

.umami-live__circle--skeleton {
  animation: umami-bar-pulse 1.4s ease-in-out infinite;
}

.umami-live__count {
  font-weight: 500;
  line-height: 1;
  font-variant-numeric: tabular-nums;
  grid-area: 1 / 1;
}

.umami-live__count--skeleton {
  visibility: hidden;
}

/* A square box centered on the circle, spun by the animation. Its width is sized so
   that the satellite — riding the box's top edge — sits exactly --umami-live-gap
   beyond the circle's edge, tracing a concentric path at a fixed distance.
   animation-duration is set inline per visitor count. */
.umami-live__orbit {
  position: absolute;
  top: 50%;
  left: 50%;
  width: calc(var(--umami-live-circle) + 2 * var(--umami-live-gap) + var(--umami-live-satellite));
  aspect-ratio: 1;
  transform: translate(-50%, -50%);
  animation-name: umami-orbit;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
  pointer-events: none;
}

.umami-live__satellite {
  position: absolute;
  top: 0;
  left: 50%;
  width: var(--umami-live-satellite);
  height: var(--umami-live-satellite);
  border-radius: 50%;
  background-color: var(--umami-fg);
  transform: translate(-50%, -50%);
}

@keyframes umami-orbit {
  from {
    transform: translate(-50%, -50%) rotate(0deg);
  }
  to {
    transform: translate(-50%, -50%) rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .umami-live__circle--skeleton,
  .umami-live__orbit {
    animation: none;
  }
}
</style>

<style>
div[data-type='szenario\\craftumamiis\\widgets\\UmamiIsLiveVisitorsWidget'] .widget-heading {
  display: none;
}
</style>

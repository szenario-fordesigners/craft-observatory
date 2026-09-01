<script setup lang="ts">
import { VisXYContainer, VisLine, VisAxis, VisCrosshair, VisTooltip } from '@unovis/vue'
import { computed, ref, onMounted } from 'vue'
import type { AnalyticsPageviews } from '@/shared/analyticsTypes';

const props = defineProps<{
    pageviews: AnalyticsPageviews | null;
}>();

type DataRecord = { x: number, y: number }

// Unovis applies the line color as an SVG `stroke` attribute, which can't resolve a
// CSS var(), so we read the resolved --observatory-fg (defined on #observatory-wrapper,
// inherited here) at mount and pass it as the line color. Falls back to the token value.
const rootEl = ref<HTMLElement | null>(null);
const lineColor = ref('#72715a');
onMounted(() => {
    const resolved = rootEl.value
        ? getComputedStyle(rootEl.value).getPropertyValue('--observatory-fg').trim()
        : '';
    if (resolved) lineColor.value = resolved;
});

// The backend emits bucket timestamps as naive "YYYY-MM-DD[ HH:MM:SS]" wall-clock
// strings already computed in the site's configured timezone (see StatsReport /
// PostHogAnalyticsSource). `new Date(str)` would re-interpret that string relative to
// the *viewer's* browser timezone instead, shifting points to the wrong hour/day for
// any viewer whose browser timezone differs from the site's — and can fail to parse
// entirely on engines that reject the non-ISO space-separated form. Parsing the
// components ourselves and building the Date from local numeric parts (same pattern
// as `formatDay()` in Usage.vue/Visitors.vue) pins the displayed wall-clock time to
// what the backend intended, independent of the browser's own timezone.
const parseWallClock = (value: string): number => {
    const match = value.match(/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2}):(\d{2}))?/);
    if (!match) return NaN;
    const [, y, mo, d, h, mi, s] = match;
    return new Date(Number(y), Number(mo) - 1, Number(d), Number(h ?? 0), Number(mi ?? 0), Number(s ?? 0)).getTime();
};

const chartData = computed<DataRecord[]>(() => {
    if (!props.pageviews || !props.pageviews.pageviews) {
        return [];
    }

    // Unovis expects x to be a number (e.g., timestamp) for time scales
    return props.pageviews.pageviews.map((pv: { t?: string; x?: string; y: number | string }) => ({
        x: parseWallClock(pv.t || pv.x || ''),
        y: Number(pv.y)
    })).filter((d: DataRecord) => !isNaN(d.x) && !isNaN(d.y));
});

// Format the timestamp as a readable date for the X-axis
const tickFormat = (x: number) => {
    const data = chartData.value;
    if (data.length > 0) {
        const last = data[data.length - 1];
        const first = data[0];
        if (last && first) {
            const spanMs = last.x - first.x;
            // If data spans 2 days or less, show hours. Otherwise date.
            if (spanMs <= 48 * 60 * 60 * 1000) {
                return new Date(x).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            }
        }
    }
    return new Date(x).toLocaleDateString([], { month: 'short', day: 'numeric' });
};

const hoverTemplate = (d: DataRecord) => `${tickFormat(d.x)}: ${d.y.toLocaleString()}`;

</script>

<template>
    <div ref="rootEl">
        <VisXYContainer :data="chartData" class="observatory-cp-line" height="250">
            <VisLine :color="lineColor" :x="(d: DataRecord) => d.x" :y="(d: DataRecord) => d.y" />
            <VisCrosshair :color="lineColor" :template="hoverTemplate" />
            <VisTooltip />
            <VisAxis type="x" :tickFormat="tickFormat" />
            <VisAxis type="y" />
        </VisXYContainer>
    </div>
</template>

<style scoped>
/* Recolor the Unovis axes to the observatory palette so the CP chart matches the
   widgets, which use --observatory-fg throughout. The line stroke is set via the
   :color prop (see script) because Unovis applies it as an SVG attribute. */
.observatory-cp-line {
    --vis-axis-tick-label-color: color-mix(in srgb, var(--observatory-fg) 70%, transparent);
    --vis-axis-grid-color: color-mix(in srgb, var(--observatory-fg) 15%, transparent);
    --vis-axis-tick-color: color-mix(in srgb, var(--observatory-fg) 15%, transparent);
    --vis-axis-domain-color: color-mix(in srgb, var(--observatory-fg) 15%, transparent);
}
</style>

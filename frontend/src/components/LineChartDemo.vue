<script setup lang="ts">
import { VisXYContainer, VisLine, VisAxis } from '@unovis/vue'
import { computed } from 'vue'
import type { WebsitePageviews } from '@umami/api-client';

const props = defineProps<{
    pageviews: WebsitePageviews | null;
}>();

type DataRecord = { x: number, y: number }

const chartData = computed<DataRecord[]>(() => {
    if (!props.pageviews || !props.pageviews.pageviews) {
        return [];
    }

    // Unovis expects x to be a number (e.g., timestamp) for time scales
    return props.pageviews.pageviews.map((pv: any) => ({
        x: new Date(pv.t || pv.x).getTime(),
        y: Number(pv.y)
    })).filter(d => !isNaN(d.x) && !isNaN(d.y));
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

</script>

<template>
    <VisXYContainer height="250">
        <VisLine :data="chartData" :x="(d: DataRecord) => d.x" :y="(d: DataRecord) => d.y" />
        <VisAxis type="x" :tickFormat="tickFormat" />
        <VisAxis type="y" />
    </VisXYContainer>
</template>
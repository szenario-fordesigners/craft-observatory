<script setup lang="ts">
import { computed } from 'vue'

interface SiteStats {
  visitors?: number
  unique?: number
  visits?: number
  pageviews?: number
  sessionDurationSeconds?: number
  comparison?: {
    visitors?: number
    unique?: number
    visits?: number
    pageviews?: number
    sessionDurationSeconds?: number
  }
}

const props = defineProps<{
  stats: SiteStats | null,
  loading: boolean
}>()

const formatNumber = (num: number) => {
  if (num >= 1000000) return (num / 1000000).toFixed(1) + 'm'
  if (num >= 1000) return (num / 1000).toFixed(1) + 'k'
  return num ? num.toString() : '0'
}

const formatTime = (seconds: number) => {
  if (!seconds) return '0m 0s'
  const m = Math.floor(seconds / 60)
  const s = Math.floor(seconds % 60)
  return `${m}m ${s}s`
}

const formatPct = (change: number) => {
    if (!change || change === 0) return '0%'
    return `${change > 0 ? '+' : ''}${Math.round(change)}%`
}

const formatedStats = computed(() => {
    if (!props.stats || Object.keys(props.stats).length === 0) return null;

    const statsObj = props.stats;
    const compObj = statsObj.comparison || {};

    const visitors = statsObj.visitors || statsObj.unique || 0;
    const prevVisitors = compObj.visitors || compObj.unique || 0;

    const visits = statsObj.visits || 0;
    const prevVisits = compObj.visits || 0;

    const pageviews = statsObj.pageviews || 0;
    const prevPageviews = compObj.pageviews || 0;

    const sessionDurationSeconds = statsObj.sessionDurationSeconds || 0;
    const prevSessionDurationSeconds = compObj.sessionDurationSeconds || 0;

    const calculateChange = (curr: number, prev: number) => {
        if (prev === 0) return curr > 0 ? 100 : 0;
        return ((curr - prev) / prev) * 100;
    };

    const currDuration = visits > 0 ? sessionDurationSeconds / visits : 0;
    const prevDuration = prevVisits > 0 ? prevSessionDurationSeconds / prevVisits : 0;
    const durationChange = calculateChange(currDuration, prevDuration);

    const visitorsChange = calculateChange(visitors, prevVisitors);
    const visitsChange = calculateChange(visits, prevVisits);
    const pageviewsChange = calculateChange(pageviews, prevPageviews);

    return {
        visitors: {
            value: formatNumber(visitors),
            change: visitorsChange,
            formatChange: formatPct(visitorsChange),
            trend: Math.sign(visitorsChange || 0),
            reverseColor: false
        },
        visits: {
            value: formatNumber(visits),
            change: visitsChange,
            formatChange: formatPct(visitsChange),
            trend: Math.sign(visitsChange || 0),
            reverseColor: false
        },
        pageviews: {
            value: formatNumber(pageviews),
            change: pageviewsChange,
            formatChange: formatPct(pageviewsChange),
            trend: Math.sign(pageviewsChange || 0),
            reverseColor: false
        },
        visitDuration: {
            value: formatTime(currDuration),
            change: durationChange,
            formatChange: formatPct(durationChange),
            trend: Math.sign(durationChange || 0),
            reverseColor: false
        }
    }
})


</script>

<template>
  <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    
    <template v-if="loading">
        <div v-for="i in 4" :key="i" class="bg-white rounded-lg p-6 border border-gray-100 shadow-sm animate-pulse">
            <div class="h-4 bg-gray-200 rounded w-1/2 mb-4 mx-auto"></div>
            <div class="h-8 bg-gray-200 rounded w-3/4 mb-4 mx-auto"></div>
            <div class="h-4 bg-gray-200 rounded w-1/4 mx-auto"></div>
        </div>
    </template>
    
    <template v-else-if="formatedStats">
        <div v-for="(stat, key) in formatedStats" :key="key" class="bg-white rounded-lg p-6 border border-gray-100 shadow-sm flex flex-col items-center justify-center text-center">
            
            <div class="text-sm text-gray-500 font-medium mb-2 capitalize">{{ String(key).replace(/([A-Z])/g, ' $1').trim() }}</div>
            <div class="text-3xl font-bold text-gray-800 mb-2">{{ stat.value }}</div>
            
            <div class="text-sm font-semibold flex items-center justify-center gap-1" :class="[
                stat.trend > 0 ? (stat.reverseColor ? 'text-red-500' : 'text-green-500') : '',
                stat.trend < 0 ? (stat.reverseColor ? 'text-green-500' : 'text-red-500') : '',
                stat.trend === 0 ? 'text-gray-400' : ''
            ]">
                <svg v-if="stat.trend > 0" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"></path></svg>
                <svg v-else-if="stat.trend < 0" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"></path></svg>
                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14"></path></svg>
                {{ stat.formatChange }}
            </div>
            
        </div>
    </template>
    
  </div>
</template>

<template>
  <div class="umami-cp-app">
    <p>
      Daily totals for the last 30 days. Historical data is loaded from your local database; missing
      days are filled by the background queue.
    </p>

    <div class="tablepane">
      <table class="data fullwidth">
        <thead>
          <tr>
            <th>Date</th>
            <th>Pageviews</th>
            <th>Visitors</th>
            <th>Visits</th>
            <th>Bounces</th>
            <th>Total Time (s)</th>
            <th>Source</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="!stats || stats.length === 0">
            <td colspan="7">
              No metrics available. Make sure your credentials are configured correctly.
            </td>
          </tr>
          <tr v-for="(row, index) in stats" :key="index">
            <td>
              <strong>{{ formatDate(row.date) }}</strong>
              <span v-if="isToday(row.date)"> (Today)</span>
            </td>
            <td>{{ row.pageviews }}</td>
            <td>{{ row.visitors }}</td>
            <td>{{ row.visits }}</td>
            <td>{{ row.bounces }}</td>
            <td>
              {{ row.totaltime }}
              <span class="light">s</span>
            </td>
            <td>
              <span class="status" :class="row.source === 'DB' ? 'green' : 'yellow'"></span>
              {{ row.source || 'API' }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { format, isToday as dateFnsIsToday } from 'date-fns';

export interface StatRow {
  date: string;
  pageviews: number;
  visitors: number;
  visits: number;
  bounces: number;
  totaltime: number;
  source: string;
}

defineProps<{
  stats?: StatRow[];
}>();

const formatDate = (dateStr: string) => {
  if (!dateStr) return '';
  return format(new Date(dateStr), 'yyyy-MM-dd');
};

const isToday = (dateStr: string) => {
  if (!dateStr) return false;
  return dateFnsIsToday(new Date(dateStr));
};
</script>

<style scoped>
/* Any custom styles not covered by Craft's native CSS can go here */
</style>

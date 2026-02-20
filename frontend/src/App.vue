<script setup lang="ts">
import LineChartDemo from '@/components/LineChartDemo.vue';

const { title } = defineProps<{
  title: string;
}>();
</script>

<template>
  <div id="umami-is-wrapper">
    <h1 class="text-2xl text-black font-bold">Hello World</h1>
    <p>Passed via props:{{ title }}</p>

    <LineChartDemo />
  </div>
</template>

<style scoped></style>

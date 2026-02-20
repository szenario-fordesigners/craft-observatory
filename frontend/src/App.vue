<script setup lang="ts">
const { title } = defineProps<{
  title: string;
}>();
</script>

<template>
  <div id="umami-is-wrapper">
    <h1 class="text-2xl text-white font-bold">Hello World</h1>
    <p>Passed via props:{{ title }}</p>
  </div>
</template>

<style scoped></style>

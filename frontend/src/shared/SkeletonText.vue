<script setup lang="ts">
defineProps<{
  variant?: 'total' | 'narrow';
}>();
</script>

<template>
  <span class="umami-skeleton" :class="variant ? `umami-skeleton--${variant}` : null"></span>
</template>

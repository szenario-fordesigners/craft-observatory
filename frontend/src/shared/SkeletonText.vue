<script setup lang="ts">
defineProps<{
  variant?: 'total' | 'narrow';
}>();
</script>

<template>
  <span class="observatory-skeleton" :class="variant ? `observatory-skeleton--${variant}` : null"></span>
</template>

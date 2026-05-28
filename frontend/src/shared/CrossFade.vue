<script setup lang="ts">
defineProps<{
  /** Vue <Transition> mode. Default is simultaneous; pass 'out-in' to fully fade the
      leaving element before the entering one mounts. */
  mode?: 'out-in' | 'in-out';
}>();
</script>

<template>
  <Transition name="umami-fade" :mode="mode">
    <slot />
  </Transition>
</template>

<script setup lang="ts">
defineProps<{ text: string }>();
</script>

<template>
  <div class="observatory-tooltip-host">
    <slot />
    <span v-if="text" class="observatory-tooltip-host__bubble" role="tooltip">{{ text }}</span>
  </div>
</template>

<style scoped>
.observatory-tooltip-host {
  position: relative;
}

.observatory-tooltip-host__bubble {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 50%;
  transform: translateX(-50%);
  white-space: nowrap;
  background-color: var(--observatory-fg);
  color: var(--observatory-bg);
  font-size: 0.7rem;
  line-height: 1;
  padding: 4px 7px;
  border-radius: 3px;
  pointer-events: none;
  opacity: 0;
  transition: opacity 0.12s ease;
  z-index: 10;
}

/* Down-pointing arrow */
.observatory-tooltip-host__bubble::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 50%;
  transform: translateX(-50%);
  border: 4px solid transparent;
  border-top-color: var(--observatory-fg);
}

.observatory-tooltip-host:hover .observatory-tooltip-host__bubble,
.observatory-tooltip-host:focus-within .observatory-tooltip-host__bubble {
  opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
  .observatory-tooltip-host__bubble {
    transition: none;
  }
}
</style>

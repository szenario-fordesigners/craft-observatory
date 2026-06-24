<template>
  <div class="umami-widget">
    <slot />
    <slot name="footer">
      <div class="umami-widget__footer">powered by analytics source</div>
    </slot>
  </div>
</template>

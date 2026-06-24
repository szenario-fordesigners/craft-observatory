<template>
  <div class="observatory-widget">
    <slot />
    <slot name="footer">
      <div class="observatory-widget__footer">powered by analytics source</div>
    </slot>
  </div>
</template>

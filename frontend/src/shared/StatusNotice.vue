<script setup lang="ts">
import { computed } from 'vue';

export interface UmamiStatus {
  configured: boolean;
  apiKeyValid: boolean;
}

const props = defineProps<{
  status: UmamiStatus | null | undefined;
  /** 'cp' = full banner for the CP page; 'widget' = compact inline notice */
  variant?: 'cp' | 'widget';
}>();

const variant = computed(() => props.variant ?? 'cp');

const state = computed<'ok' | 'unconfigured' | 'invalid-key'>(() => {
  if (!props.status) return 'ok';
  if (!props.status.configured) return 'unconfigured';
  if (!props.status.apiKeyValid) return 'invalid-key';
  return 'ok';
});

const heading = computed(() =>
  state.value === 'unconfigured'
    ? 'Umami is not configured'
    : 'Umami API key is invalid',
);

const message = computed(() =>
  state.value === 'unconfigured'
    ? 'Set the Umami URL, Website ID and API Key in plugin settings to start collecting stats.'
    : 'Stats can’t be loaded — Umami rejected the API key. Check the key in plugin settings.',
);

// Best-effort link to the plugin settings page. Falls back to "#" if Craft helper isn’t available.
const settingsUrl = computed(() => {
  if (typeof window !== 'undefined' && window.Craft?.getCpUrl) {
    return window.Craft.getCpUrl('settings/plugins/umami-is');
  }
  return '#';
});
</script>

<template>
  <template v-if="state !== 'ok'">
    <!-- CP page: full banner -->
    <div v-if="variant === 'cp'" class="umami-status-cp">
      <div class="umami-status-cp__icon" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="12" cy="12" r="10" />
          <line x1="12" y1="8" x2="12" y2="12" />
          <line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
      </div>
      <div class="umami-status-cp__body">
        <div class="umami-status-cp__heading">{{ heading }}</div>
        <div class="umami-status-cp__message">{{ message }}</div>
      </div>
      <a :href="settingsUrl" class="umami-status-cp__cta">Open settings</a>
    </div>

    <!-- Widget: compact notice -->
    <div v-else class="umami-status-widget">
      <div class="umami-status-widget__heading">{{ heading }}</div>
      <div class="umami-status-widget__message">{{ message }}</div>
      <a :href="settingsUrl" class="umami-status-widget__cta">Open settings →</a>
    </div>
  </template>
</template>

<style scoped>
/* CP page banner */
.umami-status-cp {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  margin-bottom: 1rem;
  border: 1px solid #fca5a5;
  background-color: #fef2f2;
  color: #991b1b;
  border-radius: 6px;
}

.umami-status-cp__icon {
  flex-shrink: 0;
  width: 1.25rem;
  height: 1.25rem;
  margin-top: 1px;
}
.umami-status-cp__icon svg {
  width: 100%;
  height: 100%;
}

.umami-status-cp__body {
  flex: 1;
  min-width: 0;
}

.umami-status-cp__heading {
  font-weight: 600;
  font-size: 0.875rem;
  margin-bottom: 0.15rem;
}

.umami-status-cp__message {
  font-size: 0.8rem;
  opacity: 0.85;
}

.umami-status-cp__cta {
  flex-shrink: 0;
  align-self: center;
  font-size: 0.8rem;
  font-weight: 600;
  color: #991b1b;
  text-decoration: underline;
  white-space: nowrap;
}
.umami-status-cp__cta:hover {
  opacity: 0.8;
}

/* Widget notice */
.umami-status-widget {
  background-color: color-mix(in srgb, var(--umami-fg) 12%, transparent);
  border-left: 3px solid var(--umami-fg);
  padding: 0.6rem 0.75rem;
  border-radius: 4px;
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
}

.umami-status-widget__heading {
  font-weight: 600;
  font-size: 0.85rem;
}

.umami-status-widget__message {
  font-size: 0.7rem;
  opacity: 0.8;
  line-height: 1.3;
}

.umami-status-widget__cta {
  margin-top: 0.25rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--umami-fg);
  text-decoration: underline;
  align-self: flex-start;
}
.umami-status-widget__cta:hover {
  opacity: 0.7;
}
</style>

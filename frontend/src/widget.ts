import './assets/tailwind.css';
import './assets/main.scss';
import './shared/styles.css';

import { createApp, type App, type Component } from 'vue';
import Visitors from './widget/Visitors.vue';
import WorldMap from './widget/WorldMap.vue';
import Referrers from './widget/Referrers.vue';
import Countries from './widget/Countries.vue';
import Devices from './widget/Devices.vue';
import Events from './widget/Events.vue';
import Heatmap from './widget/Heatmap.vue';
import LiveVisitors from './widget/LiveVisitors.vue';
import Usage from './widget/Usage.vue';

const widgets: Record<string, Component> = {
  visitors: Visitors,
  'world-map': WorldMap,
  referrers: Referrers,
  countries: Countries,
  devices: Devices,
  events: Events,
  heatmap: Heatmap,
  'live-visitors': LiveVisitors,
  usage: Usage,
};

// Keyed by element so a removed widget can actually be torn down — tracking only *that* an
// element was mounted left no handle to unmount with.
const apps = new WeakMap<HTMLElement, App>();

function mountWidget(el: HTMLElement): void {
  if (apps.has(el)) return;
  const name = el.dataset.observatoryWidget;
  if (!name) return;
  const component = widgets[name];
  if (!component) {
    console.warn(`Unknown observatory widget: ${name}`);
    return;
  }
  const props = JSON.parse(el.dataset.props || '{}');
  const app = createApp(component, props);
  apps.set(el, app);
  app.mount(el);
}

/**
 * Tears down a widget whose element has left the document.
 *
 * Craft deletes a widget by removing its whole grid item (Dashboard.js `destroy()`), which
 * never runs Vue's teardown. Without this, `onUnmounted` never fires, so none of the
 * `clearInterval` cleanup the components register ever runs and the widget keeps polling —
 * and keeps its component tree alive — for the rest of the page's life.
 *
 * Reordering moves the same element rather than discarding it. MutationObserver callbacks run
 * after the current task, so an element moved synchronously already reads as connected here
 * and is left mounted.
 */
function unmountWidget(el: HTMLElement): void {
  const app = apps.get(el);
  if (!app || el.isConnected) return;
  apps.delete(el);
  app.unmount();
}

function eachWidget(root: Document | HTMLElement, fn: (el: HTMLElement) => void): void {
  if (root instanceof HTMLElement && root.dataset.observatoryWidget) {
    fn(root);
  }
  root.querySelectorAll<HTMLElement>('[data-observatory-widget]').forEach(fn);
}

function initWidgets(): void {
  eachWidget(document, mountWidget);

  new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (node instanceof HTMLElement) eachWidget(node, mountWidget);
      }
      // Detached subtrees still answer querySelectorAll, so this also catches the widget
      // when it is a descendant of the removed node — which is how Craft removes it.
      for (const node of mutation.removedNodes) {
        if (node instanceof HTMLElement) eachWidget(node, unmountWidget);
      }
    }
  }).observe(document.body, { childList: true, subtree: true });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initWidgets, { once: true });
} else {
  initWidgets();
}

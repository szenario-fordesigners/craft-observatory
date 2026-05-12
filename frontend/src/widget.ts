import './assets/tailwind.css';
import './assets/main.scss';
import './shared/styles.css';

import { createApp, type Component } from 'vue';
import Summary from './widget/WidgetSummary.vue';
import WorldMap from './widget/WorldMap.vue';
import Referrers from './widget/Referrers.vue';
import Countries from './widget/Countries.vue';
import Devices from './widget/Devices.vue';
import Heatmap from './widget/Heatmap.vue';

const widgets: Record<string, Component> = {
  summary: Summary,
  'world-map': WorldMap,
  referrers: Referrers,
  countries: Countries,
  devices: Devices,
  heatmap: Heatmap,
};

const mounted = new WeakSet<HTMLElement>();

function mountWidget(el: HTMLElement): void {
  if (mounted.has(el)) return;
  const name = el.dataset.umamiWidget;
  if (!name) return;
  const component = widgets[name];
  if (!component) {
    console.warn(`Unknown umami widget: ${name}`);
    return;
  }
  mounted.add(el);
  const props = JSON.parse(el.dataset.props || '{}');
  createApp(component, props).mount(el);
}

function mountAll(root: Document | HTMLElement): void {
  (root as Element).querySelectorAll<HTMLElement>('[data-umami-widget]').forEach(mountWidget);
}

document.addEventListener('DOMContentLoaded', () => {
  mountAll(document);

  new MutationObserver((mutations) => {
    for (const mutation of mutations) {
      for (const node of mutation.addedNodes) {
        if (!(node instanceof HTMLElement)) continue;
        if (node.dataset.umamiWidget) {
          mountWidget(node);
        } else {
          mountAll(node);
        }
      }
    }
  }).observe(document.body, { childList: true, subtree: true });
});

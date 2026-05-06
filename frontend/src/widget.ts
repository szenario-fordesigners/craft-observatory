import './assets/tailwind.css';
import './assets/main.scss';
import './shared/styles.css';

import { createApp, type Component } from 'vue';
import Summary from './widget/Summary.vue';
import WorldMap from './widget/WorldMap.vue';

const widgets: Record<string, Component> = {
  summary: Summary,
  'world-map': WorldMap,
};

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll<HTMLElement>('[data-umami-widget]').forEach((el) => {
    const name = el.dataset.umamiWidget;
    if (!name) return;

    const component = widgets[name];
    if (!component) {
      console.warn(`Unknown umami widget: ${name}`);
      return;
    }

    const props = JSON.parse(el.dataset.props || '{}');
    createApp(component, props).mount(el);
  });
});

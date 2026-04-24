import './assets/tailwind.css';
import './assets/main.scss';

import { createApp } from 'vue';
import CpApp from './CpApp.vue';

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('#umami-is-cp-app').forEach((el) => {
    const props = JSON.parse((el as HTMLElement).dataset.props || '{}');
    const app = createApp(CpApp, props);
    app.mount(el);
  });
});

import './shared/styles.css';
import './assets/tailwind.css';
import './assets/main.scss';

import { createApp } from 'vue';
import CpApp from './CpApp.vue';

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('#observatory-cp-app').forEach((el) => {
    const props = JSON.parse((el as HTMLElement).dataset.props || '{}');
    const app = createApp(CpApp, props);
    app.mount(el);
  });
});

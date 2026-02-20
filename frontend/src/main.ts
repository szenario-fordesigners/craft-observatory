import './assets/tailwind.css';
import './assets/main.scss';

import { createApp } from 'vue';
import App from './App.vue';

// dom content loaded
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('#umami-is-app').forEach((el) => {
    const props = JSON.parse((el as HTMLElement).dataset.props || '{}');
    const app = createApp(App, props);
    app.mount(el);
  });
});

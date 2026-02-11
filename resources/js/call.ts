/**
 * Standalone Call App Entry Point
 * Minimal Vue app — only loads Echo + WebRTC dependencies.
 * No router, no sidebar, no full Pinia.
 */
import '../css/call.css';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import CallApp from './views/call/CallApp.vue';

const app = createApp(CallApp);
const pinia = createPinia();
app.use(pinia);

app.config.errorHandler = (err: unknown, _instance, info: string) => {
  console.error('[Call Error]', err);
  console.error('[Info]', info);
};

app.mount('#call-app');
